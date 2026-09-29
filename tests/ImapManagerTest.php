<?php

use DirectoryTree\ImapEngine\Laravel\ImapManager;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;

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

it('disconnects a forgotten mailbox and rebuilds it from configuration', function () {
    $manager = new ImapManager(['mailboxes' => ['default' => ['host' => 'imap.example.com']]]);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('disconnect')->once();

    $manager->swap('default', $mailbox);

    expect($manager->forget('default'))->toBe($manager);

    $replacement = $manager->mailbox('default');

    expect($replacement)->not->toBe($mailbox);
    expect($replacement->config('host'))->toBe('imap.example.com');
    expect($manager->mailbox('default'))->toBe($replacement);
});

it('does not resolve a mailbox when forgetting an uncached name', function () {
    $manager = new ImapManager([]);

    expect($manager->forget('missing'))->toBe($manager);
});

it('disconnects the previous mailbox when swapping instances', function () {
    $manager = new ImapManager([]);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('disconnect')->once();

    $replacement = FakeMailbox::make();

    $manager->swap('default', $mailbox);
    $manager->swap('default', $replacement);

    expect($manager->mailbox('default'))->toBe($replacement);
});

it('does not disconnect when swapping in the same mailbox', function () {
    $manager = new ImapManager([]);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldNotReceive('disconnect');

    $manager->swap('default', $mailbox);
    $manager->swap('default', $mailbox);

    expect($manager->mailbox('default'))->toBe($mailbox);
});

it('disconnects the previous mailbox when registering a replacement', function () {
    $manager = new ImapManager([]);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('disconnect')->once();

    $manager->swap('default', $mailbox);

    expect($manager->register('default', ['host' => 'imap.new.example.com']))->toBe($manager);

    expect($manager->mailbox('default')->config('host'))->toBe('imap.new.example.com');
});

it('leaves other cached mailboxes connected when forgetting a mailbox', function () {
    $manager = new ImapManager([]);

    $default = Mockery::mock(MailboxInterface::class);
    $default->shouldReceive('disconnect')->once();

    $secondary = Mockery::mock(MailboxInterface::class);
    $secondary->shouldNotReceive('disconnect');

    $manager->swap('default', $default);
    $manager->swap('secondary', $secondary);
    $manager->forget('default');

    expect($manager->mailbox('secondary'))->toBe($secondary);
});
