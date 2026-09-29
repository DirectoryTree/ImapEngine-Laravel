<?php

namespace DirectoryTree\ImapEngine\Laravel\Tests;

use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;
use DirectoryTree\ImapEngine\Exceptions\ImapStreamException;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Commands\WatchMailbox;
use DirectoryTree\ImapEngine\Laravel\Events\MailboxWatchAttemptsExceeded;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\Laravel\Support\LoopFake;
use DirectoryTree\ImapEngine\Laravel\Support\LoopInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageData\FetchItemInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidOptionException;

use function Pest\Laravel\artisan;

it('throws exception when mailbox is not configured', function () {
    artisan(WatchMailbox::class, ['mailbox' => 'invalid']);
})->throws(
    InvalidArgumentException::class,
    'Mailbox [invalid] is not defined. Please check your IMAP configuration.'
);

it('can watch mailbox', function () {
    Config::set('imap.mailboxes.test', [
        'host' => 'localhost',
        'port' => 993,
        'encryption' => 'ssl',
        'username' => '',
        'password' => '',
    ]);

    Imap::fake('test', folders: [
        new FakeFolder('inbox', messages: [
            $message = new FakeMessage(uid: 1),
        ]),
    ]);

    App::bind(LoopInterface::class, LoopFake::class);

    Event::fake();

    artisan(WatchMailbox::class, ['mailbox' => 'test'])->assertSuccessful();

    Event::assertDispatched(fn (MessageReceived $event) => (
        $event->message->is($message) && $event->mailbox === 'test'
    ));
});

it('can watch mailbox using method', function (string $method) {
    Config::set('imap.mailboxes.test', [
        'host' => 'localhost',
        'port' => 993,
        'encryption' => 'ssl',
        'username' => '',
        'password' => '',
    ]);

    Imap::fake('test', folders: [
        new FakeFolder('inbox', messages: [
            $message = new FakeMessage(uid: 1),
        ]),
    ]);

    App::bind(LoopInterface::class, LoopFake::class);

    Event::fake();

    artisan(WatchMailbox::class, [
        'mailbox' => 'test',
        '--method' => $method,
        '--with' => 'flags,body,headers',
    ])->assertSuccessful();

    Event::assertDispatched(fn (MessageReceived $event) => (
        $event->message->is($message) && $event->mailbox === 'test'
    ));
})->with(['idle', 'poll']);

it('limits connection retries and disconnects before retrying', function (string $method, string $exception, int $retries) {
    Sleep::fake();

    $failure = new $exception('Simulated connection failure');

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive($method)->times($retries + 1)->andThrow($failure);

    $mailbox = Mockery::mock(MailboxInterface::class);

    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $mailbox->shouldReceive('inbox')->once()->ordered()->andReturn($folder);
        $mailbox->shouldReceive('disconnect')->once()->ordered();
    }

    Imap::shouldReceive('mailbox')->once()->with('test')->andReturn($mailbox);

    Event::fake();

    expect(function () use ($method, $retries) {
        artisan(WatchMailbox::class, [
            'mailbox' => 'test',
            '--method' => $method,
            '--attempts' => $retries,
        ]);
    })->toThrow($failure);

    Event::assertDispatchedTimes(MailboxWatchAttemptsExceeded::class, 1);

    Event::assertDispatched(function (MailboxWatchAttemptsExceeded $event) use ($retries, $failure) {
        return $event->attempts === $retries
            && $event->mailbox === 'test'
            && is_null($event->lastReceivedAt)
            && $event->exception === $failure;
    });

    Sleep::assertSleptTimes($retries);
})->with(['idle', 'poll', 'events'])->with([
    'closed connection' => ImapConnectionClosedException::class,
    'failed connection' => ImapConnectionFailedException::class,
    'timed out connection' => ImapConnectionTimedOutException::class,
    'stream failure' => ImapStreamException::class,
])->with([0, 2]);

it('throws exception when invalid method is provided', function () {
    Config::set('imap.mailboxes.test', [
        'host' => 'localhost',
        'port' => 993,
        'encryption' => 'ssl',
        'username' => '',
        'password' => '',
    ]);

    Imap::fake('test', folders: [
        new FakeFolder('inbox'),
    ]);

    App::bind(LoopInterface::class, LoopFake::class);

    artisan(WatchMailbox::class, [
        'mailbox' => 'test',
        '--method' => 'invalid',
    ]);
})->throws(
    InvalidOptionException::class,
    'Invalid method [invalid]. Valid options are [idle, poll, events].'
);

it('rejects invalid options before resolving a mailbox', function (string $option, string $value) {
    Imap::shouldReceive('mailbox')->never();

    artisan(WatchMailbox::class, ['mailbox' => 'test', $option => $value]);
})->with([
    'unknown message part' => ['--with', 'flags,invalid'],
    'zero message part' => ['--with', '0'],
])->throws(InvalidOptionException::class);

it('passes normalized message parts and an integer interval to the watcher', function (string $method) {
    $query = Mockery::mock(MessageQueryInterface::class);

    $query->shouldReceive('with')->once()->withArgs(
        fn (FetchItemInterface $item) => $item->toImap() === 'FLAGS'
    )->andReturnSelf();

    $query->shouldReceive('with')->once()->withArgs(
        fn (FetchItemInterface $item) => $item->toImap() === 'BODY.PEEK[TEXT]'
    )->andReturnSelf();

    $query->shouldReceive('with')->once()->withArgs(
        fn (FetchItemInterface $item) => $item->toImap() === 'BODY.PEEK[HEADER]'
    )->andReturnSelf();

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive($method)->once()->andReturnUsing(function ($callback, $configure, $interval) use ($query) {
        expect($interval)->toBe(45);
        expect($configure($query))->toBe($query);
    });

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->once()->andReturn($folder);

    Imap::shouldReceive('mailbox')->once()->with('test')->andReturn($mailbox);

    App::bind(LoopInterface::class, LoopFake::class);

    artisan(WatchMailbox::class, [
        'mailbox' => 'test',
        '--method' => $method,
        '--timeout' => '45',
        '--with' => ' flags, body, headers, ',
    ])->assertSuccessful();
})->with(['idle', 'poll']);

it('does not retry listener failures', function (string $method, string $exception) {
    Sleep::fake();

    Config::set('imap.mailboxes.test', []);

    Imap::fake('test', folders: [
        new FakeFolder('inbox', messages: [new FakeMessage(uid: 1)]),
    ]);

    $failure = new $exception('Connection unavailable: message no longer exists');

    Event::fake([MailboxWatchAttemptsExceeded::class]);

    Event::listen(MessageReceived::class, fn () => throw $failure);

    expect(fn () => artisan(WatchMailbox::class, [
        'mailbox' => 'test',
        '--method' => $method,
    ])->run())->toThrow($failure);

    Event::assertNotDispatched(MailboxWatchAttemptsExceeded::class);

    Sleep::assertNeverSlept();
})->with(['idle', 'poll'])->with([
    'application exception' => RuntimeException::class,
    'listener connection exception' => ImapConnectionClosedException::class,
    'listener stream exception' => ImapStreamException::class,
    'application error' => \Error::class,
]);

it('does not retry non-connection watcher failures', function (string $method) {
    Sleep::fake();

    $failure = new RuntimeException('Message no longer exists');

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive($method)->once()->andThrow($failure);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->once()->andReturn($folder);
    $mailbox->shouldNotReceive('disconnect');

    Imap::shouldReceive('mailbox')->once()->with('test')->andReturn($mailbox);

    Event::fake();

    expect(fn () => artisan(WatchMailbox::class, [
        'mailbox' => 'test',
        '--method' => $method,
    ])->run())->toThrow($failure);

    Event::assertNotDispatched(MailboxWatchAttemptsExceeded::class);

    Sleep::assertNeverSlept();
})->with(['idle', 'poll']);

it('resets retries after receiving a message and records the last receipt', function (string $method) {
    Sleep::fake();

    $failure = new ImapConnectionClosedException('Connection closed');

    $message = new FakeMessage(uid: 42);

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive($method)->once()->ordered()->andThrow($failure);
    $folder->shouldReceive($method)->once()->ordered()->andReturnUsing(function ($callback) use ($message, $failure) {
        $callback($message);

        throw $failure;
    });
    $folder->shouldReceive($method)->once()->ordered()->andThrow($failure);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->times(3)->andReturn($folder);
    $mailbox->shouldReceive('disconnect')->times(3);

    Imap::shouldReceive('mailbox')->once()->with('test')->andReturn($mailbox);

    Event::fake();

    expect(fn () => artisan(WatchMailbox::class, [
        'mailbox' => 'test',
        '--method' => $method,
        '--attempts' => 1,
    ])->run())->toThrow($failure);

    Event::assertDispatchedTimes(MessageReceived::class, 1);

    Event::assertDispatched(fn (MailboxWatchAttemptsExceeded $event) => (
        $event->attempts === 1
        && $event->lastReceivedAt !== null
        && $event->exception === $failure
    ));

    Sleep::assertSleptTimes(2);
})->with(['idle', 'poll']);
