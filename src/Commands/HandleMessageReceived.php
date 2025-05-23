<?php

namespace DirectoryTree\ImapEngine\Laravel\Commands;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

class HandleMessageReceived
{
    /**
     * Constructor.
     */
    public function __construct(
        protected WatchMailbox $command,
        protected int &$attempts = 0,
        protected ?Carbon &$lastReceivedAt = null,
    ) {}

    /**
     * Handle the message received event.
     */
    public function __invoke(MessageInterface $message): void
    {
        $this->command->info(
            "Message received: [{$message->uid()}]"
        );

        $this->attempts = 0;

        $this->lastReceivedAt = Date::now();

        Event::dispatch(new MessageReceived($message));
    }
}
