<?php

use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\Idle\Events\MessageExpunged;
use DirectoryTree\ImapEngine\Idle\Events\MessageFetched;
use DirectoryTree\ImapEngine\Idle\Events\MessagesExist;
use DirectoryTree\ImapEngine\Idle\Events\MessagesVanished;
use DirectoryTree\ImapEngine\Idle\Events\UnknownEvent;
use DirectoryTree\ImapEngine\Laravel\Commands\HandleMailboxEventReceived;
use DirectoryTree\ImapEngine\Laravel\Commands\WatchMailbox;
use DirectoryTree\ImapEngine\Laravel\Events\MailboxEventReceived;
use DirectoryTree\ImapEngine\Laravel\Events\MailboxWatchAttemptsExceeded;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\Laravel\Support\LoopFake;
use DirectoryTree\ImapEngine\Laravel\Support\LoopInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Selection\Result;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

it('dispatches the original typed events with the mailbox name and folder', function (string $type, string $response) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([$response]);

    $parser = new ImapParser(new ImapTokenizer($stream));
    $event = new $type('Archive', $parser->next());

    $folder = new FakeFolder('Archive');
    $folder->setIdleEvents([$event]);

    Imap::fake('test', folders: [$folder]);

    App::bind(LoopInterface::class, LoopFake::class);

    Event::fake();

    artisan(WatchMailbox::class, [
        'mailbox' => 'test',
        'folder' => 'Archive',
        '--method' => 'events',
    ])->assertSuccessful();

    Event::assertDispatchedTimes(MailboxEventReceived::class, 2);

    Event::assertDispatched(fn (MailboxEventReceived $received) => (
        $received->mailbox === 'test'
        && $received->event === $event
        && $received->event->folder() === 'Archive'
    ));

    Event::assertDispatched(fn (MailboxEventReceived $received) => (
        $received->event instanceof FolderSelected
        && $received->event->folder() === 'Archive'
    ));

    Event::assertNotDispatched(MessageReceived::class);
})->with([
    'message count' => [MessagesExist::class, '* 10 EXISTS'],
    'flag changes' => [MessageFetched::class, '* 2 FETCH (UID 42 FLAGS (\\Seen))'],
    'expunged sequence number' => [MessageExpunged::class, '* 3 EXPUNGE'],
    'vanished UIDs' => [MessagesVanished::class, '* VANISHED 7:9'],
    'other server response' => [UnknownEvent::class, '* OK Still here'],
]);

it('does not retry mailbox event listener failures', function (string $exception) {
    Sleep::fake();

    Imap::fake('test', folders: [new FakeFolder('INBOX')]);

    $failure = new $exception('Listener failed');

    Event::fake([MailboxWatchAttemptsExceeded::class]);

    Event::listen(MailboxEventReceived::class, fn () => throw $failure);

    expect(fn () => artisan(WatchMailbox::class, [
        'mailbox' => 'test',
        '--method' => 'events',
    ])->run())->toThrow($failure);

    Event::assertNotDispatched(MailboxWatchAttemptsExceeded::class);

    Sleep::assertNeverSlept();
})->with([
    'application failure' => RuntimeException::class,
    'connection failure inside listener' => ImapConnectionClosedException::class,
]);

it('does not reset retries merely because the folder was selected again', function () {
    Sleep::fake();

    $failure = new ImapConnectionClosedException('Connection closed');
    $event = new FolderSelected('INBOX', new Result);

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('events')->twice()->andReturnUsing(function ($callback, $timeout) use ($event, $failure) {
        expect($timeout)->toBe(45);

        $callback($event);

        throw $failure;
    });

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->twice()->andReturn($folder);
    $mailbox->shouldReceive('disconnect')->twice();

    Imap::shouldReceive('mailbox')->once()->with('test')->andReturn($mailbox);

    Event::fake();

    expect(fn () => artisan(WatchMailbox::class, [
        'mailbox' => 'test',
        '--method' => 'events',
        '--timeout' => '45',
        '--attempts' => 1,
    ])->run())->toThrow($failure);

    Event::assertDispatched(fn (MailboxWatchAttemptsExceeded $received) => (
        $received->attempts === 1 && $received->lastReceivedAt === null
    ));

    Sleep::assertSleptTimes(1);
});

it('resets retries and records receipt when a mailbox update arrives', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* 3 EXPUNGE']);

    $parser = new ImapParser(new ImapTokenizer($stream));
    $event = new MessageExpunged('INBOX', $parser->next());

    $command = Mockery::mock(WatchMailbox::class);
    $command->shouldReceive('info')->once();

    $attempts = 3;
    $lastReceivedAt = null;
    $before = Date::now();

    $handler = new HandleMailboxEventReceived($command, 'test', $attempts, $lastReceivedAt);

    Event::fake();

    $handler($event);

    expect($attempts)->toBe(0);
    expect($lastReceivedAt)->not->toBeNull();
    expect($lastReceivedAt->betweenIncluded($before, Date::now()))->toBeTrue();

    Event::assertDispatched(fn (MailboxEventReceived $received) => $received->event === $event);
});
