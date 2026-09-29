<?php

namespace DirectoryTree\ImapEngine\Laravel\Events;

use DirectoryTree\ImapEngine\Idle\Events\EventInterface;

class MailboxEventReceived
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public EventInterface $event,
        public string $mailbox,
    ) {}
}
