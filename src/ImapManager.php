<?php

namespace DirectoryTree\ImapEngine\Laravel;

use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MailboxInterface;
use InvalidArgumentException;

class ImapManager
{
    /**
     * The IMAP configuration.
     */
    protected array $config = [];

    /**
     * The mailbox instances.
     */
    protected array $mailboxes = [];

    /**
     * Constructor.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get a mailbox instance.
     */
    public function mailbox(string $name): MailboxInterface
    {
        if (isset($this->mailboxes[$name])) {
            return $this->mailboxes[$name];
        }

        if (! array_key_exists($name, $this->config['mailboxes'] ?? [])) {
            throw new InvalidArgumentException(
                "Mailbox [{$name}] is not defined. Please check your IMAP configuration."
            );
        }

        return $this->mailboxes[$name] = $this->build($this->config['mailboxes'][$name]);
    }

    /**
     * Register a mailbox instance.
     */
    public function register(string $name, array $config): static
    {
        $this->mailboxes[$name] = $this->build($config);

        return $this;
    }

    /**
     * Build an on-demand mailbox instance.
     */
    public function build(array $config): MailboxInterface
    {
        return new Mailbox($config);
    }

    /**
     * Remove a mailbox from the in-memory cache.
     */
    public function forget(string $name): static
    {
        unset($this->mailboxes[$name]);

        return $this;
    }

    /**
     * Swap out a mailbox instance with a new one.
     */
    public function swap(string $name, MailboxInterface $mailbox): void
    {
        $this->mailboxes[$name] = $mailbox;
    }
}
