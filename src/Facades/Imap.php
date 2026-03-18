<?php

namespace DirectoryTree\ImapEngine\Laravel\Facades;

use DirectoryTree\ImapEngine\Laravel\ImapManager;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \DirectoryTree\ImapEngine\MailboxInterface mailbox(string $name)
 * @method static \DirectoryTree\ImapEngine\MailboxInterface swap(string $name, \DirectoryTree\ImapEngine\MailboxInterface $mailbox)
 */
class Imap extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return ImapManager::class;
    }

    /**
     * Fake the given mailbox.
     */
    public static function fake(
        string $mailbox,
        array $config = [],
        array $folders = [],
        array $capabilities = []
    ): FakeMailbox {
        /** @var ImapManager $manager */
        $manager = static::getFacadeRoot();

        $fake = new FakeMailbox($config, $folders, $capabilities);

        $manager->swap($mailbox, $fake);

        return $fake;
    }
}
