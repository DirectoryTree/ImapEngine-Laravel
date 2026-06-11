<?php

use DirectoryTree\ImapEngine\Laravel\ImapManager;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MailboxInterface;

beforeEach(function () {
    $this->config = [
        'mailboxes' => [
            'default' => [
                'host' => 'imap.example.com',
                'port' => 993,
                'username' => 'test@example.com',
                'password' => 'password',
                'encryption' => 'ssl',
            ],
            'secondary' => [
                'host' => 'imap.secondary.com',
                'port' => 993,
                'username' => 'test@secondary.com',
                'password' => 'password',
                'encryption' => 'ssl',
            ],
        ],
    ];

    $this->manager = new ImapManager($this->config);
});

it('returns a mailbox instance', function () {
    $mailbox = $this->manager->mailbox('default');

    expect($mailbox)->toBeInstanceOf(MailboxInterface::class);
    expect($mailbox)->toBeInstanceOf(Mailbox::class);
});

it('caches mailbox instances', function () {
    $mailbox1 = $this->manager->mailbox('default');
    $mailbox2 = $this->manager->mailbox('default');

    expect($mailbox1)->toBe($mailbox2);
});

it('creates different instances for different mailboxes', function () {
    $default = $this->manager->mailbox('default');
    $secondary = $this->manager->mailbox('secondary');

    expect($default)->not->toBe($secondary);
});

it('throws an exception for undefined mailboxes', function () {
    expect(fn () => $this->manager->mailbox('undefined'))
        ->toThrow(InvalidArgumentException::class, 'Mailbox [undefined] is not defined.');
});

it('registers and retrieves a new mailbox', function () {
    $this->manager->register('custom', [
        'host' => 'imap.custom.com',
        'port' => 993,
        'username' => 'user@custom.com',
        'password' => 'password',
        'encryption' => 'ssl',
    ]);

    $mailbox = $this->manager->mailbox('custom');

    expect($mailbox)->toBeInstanceOf(MailboxInterface::class);
    expect($mailbox)->toBeInstanceOf(Mailbox::class);
});
