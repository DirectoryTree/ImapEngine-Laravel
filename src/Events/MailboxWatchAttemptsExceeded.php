<?php

namespace DirectoryTree\ImapEngine\Laravel\Events;

use Carbon\CarbonInterface;
use Exception;

class MailboxWatchAttemptsExceeded
{
    /**
     * Constructor.
     */
    public function __construct(
        public string $mailbox,
        public int $attempts,
        public Exception $exception,
        public ?CarbonInterface $lastReceivedAt = null,
    ) {}
}
