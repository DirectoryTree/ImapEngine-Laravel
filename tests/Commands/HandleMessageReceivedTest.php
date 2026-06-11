<?php

use DirectoryTree\ImapEngine\Laravel\Commands\HandleMessageReceived;
use DirectoryTree\ImapEngine\Laravel\Commands\WatchMailbox;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Illuminate\Support\Facades\Event;

it('dispatches event', function () {
    $command = mock(WatchMailbox::class);

    $command->shouldReceive('info')->once()->with(
        'Message received: [123]'
    );

    Event::fake();

    $handle = new HandleMessageReceived($command);

    $handle(new FakeMessage(123));

    Event::assertDispatched(
        fn (MessageReceived $event) => is_null($event->mailbox)
    );
});

it('dispatches event with mailbox name', function () {
    $command = mock(WatchMailbox::class);

    $command->shouldReceive('info')->once()->with(
        'Message received: [123]'
    );

    Event::fake();

    $handle = new HandleMessageReceived($command, 'test');

    $handle(new FakeMessage(123));

    Event::assertDispatched(
        fn (MessageReceived $event) => $event->mailbox === 'test'
    );
});
