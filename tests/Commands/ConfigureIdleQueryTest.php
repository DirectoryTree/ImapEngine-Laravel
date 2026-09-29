<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Laravel\Commands\ConfigureIdleQuery;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageData\FetchItemInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Testing\FakeFolder;

it('does nothing when "with" is empty', function () {
    $folder = new FakeFolder;

    $configure = new ConfigureIdleQuery;

    $query = $folder->messages();

    expect($configure($query))->toBe($query);
});

it('configures the requested fetch item', function (string $part, string $item) {
    $query = Mockery::mock(MessageQueryInterface::class);

    $query->shouldReceive('with')->once()->withArgs(
        fn (FetchItemInterface $fetch) => $fetch->toImap() === $item
    )->andReturnSelf();

    $configure = new ConfigureIdleQuery([$part]);

    expect($configure($query))->toBe($query);
})->with([
    'flags' => ['flags', 'FLAGS'],
    'body without marking read' => ['body', 'BODY.PEEK[TEXT]'],
    'headers without marking read' => ['headers', 'BODY.PEEK[HEADER]'],
]);

it('adds requested items without replacing existing fetch items', function () {
    $query = Mockery::mock(MessageQueryInterface::class);

    $query->shouldReceive('with')->once()->withArgs(
        fn (FetchItemInterface $item) => $item->toImap() === 'FLAGS'
    )->andReturnSelf();

    $query->shouldReceive('with')->once()->withArgs(
        fn (FetchItemInterface $item) => $item->toImap() === 'BODY.PEEK[TEXT]'
    )->andReturnSelf();

    $query->shouldReceive('with')->once()->withArgs(
        fn (FetchItemInterface $item) => $item->toImap() === 'BODY.PEEK[HEADER]'
    )->andReturnSelf();

    $query->shouldNotReceive('only');

    $configure = new ConfigureIdleQuery(['flags', 'body', 'headers']);

    expect($configure($query))->toBe($query);
});

it('fetches the requested data through the v2 query without marking messages read', function () {
    $connection = ImapConnection::fake([
        '* OK Ready',
        'TAG1 OK Logged in',
        '* 1 EXISTS',
        'TAG2 OK Selected',
        '* SEARCH 42',
        'TAG3 OK Search completed',
        '* 1 FETCH (UID 42 FLAGS () BODY[TEXT] "Hello" BODY[HEADER] "Subject: Test")',
        'TAG4 OK Fetch completed',
    ]);

    $mailbox = new Mailbox(['host' => 'localhost', 'encryption' => null]);
    $mailbox->connect($connection);

    $folder = new Folder($mailbox, 'INBOX');
    $configure = new ConfigureIdleQuery(['flags', 'body', 'headers']);

    $message = $configure($folder->messages())->get()->first();

    expect($message->uid())->toBe(42);
    expect($message->data()->get('BODY[TEXT]'))->toBe('Hello');

    $connection->stream()->assertWritten('TAG4 UID FETCH 42 (FLAGS BODY.PEEK[TEXT] BODY.PEEK[HEADER])');
});
