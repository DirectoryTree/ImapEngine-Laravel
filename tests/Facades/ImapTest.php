<?php

use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;
use DirectoryTree\ImapEngine\Testing\FakeMessage;

beforeEach(function () {
    config(['imap' => [
        'mailboxes' => [
            'default' => [
                'host' => 'imap.example.com',
                'port' => 993,
                'username' => 'test@example.com',
                'password' => 'password',
                'encryption' => 'ssl',
            ],
        ],
    ]]);
});

it('can fake a mailbox', function () {
    $fake = Imap::fake('default');

    expect($fake)->toBeInstanceOf(FakeMailbox::class);

    expect(Imap::mailbox('default'))->toBe($fake);
});

it('can fake a mailbox with custom configuration', function () {
    $config = [
        'host' => 'fake.example.com',
        'username' => 'fake@example.com',
    ];

    $fake = Imap::fake('default', $config);

    expect($fake->config('host'))->toBe('fake.example.com');
    expect($fake->config('username'))->toBe('fake@example.com');
});

it('can fake mailbox capabilities using the v2 capability collection', function () {
    $fake = Imap::fake('default', capabilities: ['IMAP4rev1', 'IDLE', 'CONDSTORE']);

    expect($fake->capabilities()->supports('IDLE'))->toBeTrue();
    expect($fake->capabilities()->supports('CONDSTORE'))->toBeTrue();
    expect($fake->capabilities()->supports('QRESYNC'))->toBeFalse();
});

it('can fake a mailbox with folders', function () {
    $inbox = new FakeFolder('inbox', ['\\HasNoChildren']);
    $sent = new FakeFolder('sent', ['\\HasNoChildren']);

    $fake = Imap::fake('default', [], [$inbox, $sent]);

    $folders = $fake->folders()->get();
    expect($folders)->toHaveCount(2);

    expect($fake->folders()->find('inbox'))->not->toBeNull();
    expect($fake->folders()->find('sent'))->not->toBeNull();

    expect($fake->inbox()->path())->toBe('inbox');
});

it('can fake a mailbox with messages', function () {
    $message = new FakeMessage(1, ['\\Seen'], 'From: test@example.com\r\nSubject: Test Email\r\n\r\nThis is a test email.');

    $inbox = new FakeFolder('inbox', ['\\HasNoChildren'], [$message]);

    $fake = Imap::fake('default', [], [$inbox]);

    $messages = $fake->inbox()->messages()->get();
    expect($messages)->toHaveCount(1);

    $retrievedMessage = $messages->first();
    expect($retrievedMessage->uid())->toBe(1);
    expect((string) $retrievedMessage)->toContain('This is a test email.');
});

it('can append messages to a fake mailbox folder', function () {
    $inbox = new FakeFolder('inbox', ['\\HasNoChildren']);

    $fake = Imap::fake('default', [], [$inbox]);

    $messageContent = "From: sender@example.com\r\nTo: recipient@example.com\r\nSubject: Test Subject\r\n\r\nTest message body";
    $result = $fake->inbox()->messages()->append($messageContent);

    expect($result->uid())->toBe(1);

    $messages = $fake->inbox()->messages();

    $message = $messages->find(1);
    expect($message)->not->toBeNull();
    expect((string) $message)->toBe($messageContent);
});

it('can delete messages from a fake mailbox folder', function () {
    $message1 = new FakeMessage(1, ['\\Seen'], 'Message 1');
    $message2 = new FakeMessage(2, ['\\Seen'], 'Message 2');
    $message3 = new FakeMessage(3, ['\\Seen'], 'Message 3');

    $inbox = new FakeFolder('inbox', ['\\HasNoChildren'], [$message1, $message2, $message3]);

    $fake = Imap::fake('default', [], [$inbox]);

    expect($fake->inbox()->messages()->count())->toBe(3);

    $fake->inbox()->messages()->destroy(2);

    $messages = $fake->inbox()->messages();

    expect($messages->find(2))->toBeNull();
    expect($messages->find(1))->not->toBeNull();
    expect($messages->find(3))->not->toBeNull();

    $fake->inbox()->messages()->destroy([1, 3]);

    $messages = $fake->inbox()->messages();

    expect($messages->find(1))->toBeNull();
    expect($messages->find(3))->toBeNull();
});
