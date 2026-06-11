<?php

namespace DirectoryTree\ImapEngine\Laravel\Events;

use DirectoryTree\ImapEngine\MessageInterface;

class MessageReceived
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public MessageInterface $message,
        public ?string $mailbox = null,
    ) {}
}
