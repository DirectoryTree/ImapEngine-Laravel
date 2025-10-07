<?php

namespace DirectoryTree\ImapEngine\Laravel\Tests;

use DirectoryTree\ImapEngine\Laravel\Commands\WatchMailbox;
use DirectoryTree\ImapEngine\Laravel\Events\MailboxWatchAttemptsExceeded;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\Laravel\Support\LoopFake;
use DirectoryTree\ImapEngine\Laravel\Support\LoopInterface;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use RuntimeException;

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

    Event::assertDispatched(
        fn (MessageReceived $event) => $event->message->is($message)
    );
});

it('dispatches event when failure attempts have been reached', function () {
    Config::set('imap.mailboxes.test', [
        'host' => 'localhost',
        'port' => 993,
        'encryption' => 'ssl',
        'username' => '',
        'password' => '',
    ]);

    Imap::fake('test', folders: [
        new class('inbox') extends FakeFolder
        {
            public function idle(
                callable $callback,
                ?callable $query = null,
                int|callable $timeout = 300
            ): void {
                throw new RuntimeException('Simulated exception');
            }
        },
    ]);

    Event::fake();

    try {
        artisan(WatchMailbox::class, [
            'mailbox' => 'test',
            '--attempts' => 5,
        ]);
    } catch (RuntimeException) {
        // Do nothing.
    }

    Event::assertDispatched(function (MailboxWatchAttemptsExceeded $event) {
        return $event->attempts === 5
            && $event->mailbox === 'test'
            && is_null($event->lastReceivedAt)
            && $event->exception->getMessage() === 'Simulated exception';
    });
});
