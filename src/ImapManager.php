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
        $this->swap($name, $this->build($config));

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
     * Disconnect a mailbox and remove it from the in-memory cache.
     */
    public function forget(string $name): static
    {
        if (isset($this->mailboxes[$name])) {
            $this->mailboxes[$name]->disconnect();
        }

        unset($this->mailboxes[$name]);

        return $this;
    }

    /**
     * Disconnect the previous mailbox and replace it with a new instance.
     */
    public function swap(string $name, MailboxInterface $mailbox): void
    {
        if (($this->mailboxes[$name] ?? null) === $mailbox) {
            return;
        }

        $this->forget($name);

        $this->mailboxes[$name] = $mailbox;
    }
}
