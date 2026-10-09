<?php

namespace DirectoryTree\ImapEngine\Laravel\Commands;

use Carbon\CarbonInterface;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\Laravel\Events\MailboxEventReceived;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

class HandleMailboxEventReceived
{
    /**
     * Constructor.
     */
    public function __construct(
        protected WatchMailbox $command,
        protected string $mailbox,
        protected int &$attempts = 0,
        protected ?CarbonInterface &$lastReceivedAt = null,
    ) {}

    /**
     * Dispatch the original typed mailbox event through Laravel.
     */
    public function __invoke(EventInterface $event): void
    {
        $this->command->info("Mailbox event received: [{$event->type()}] in [{$event->folder()}]");

        // Selecting the folder after reconnecting must not reset the retry budget.
        if (! $event instanceof FolderSelected) {
            $this->attempts = 0;

            $this->lastReceivedAt = Date::now();
        }

        Event::dispatch(new MailboxEventReceived($event, $this->mailbox));
    }
}
