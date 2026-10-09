<?php

use DirectoryTree\ImapEngine\Laravel\ImapManager;
use DirectoryTree\ImapEngine\Laravel\ImapServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

it('loads the package configuration without publishing it', function () {
    $defaults = require __DIR__.'/../config/imap.php';

    expect(Config::get('imap'))->toBe($defaults);

    $manager = App::make(ImapManager::class);

    expect($manager->mailbox('default')->config('authentication'))
        ->toBe($defaults['mailboxes']['default']['authentication']);
});

it('preserves the application mailbox configuration', function () {
    $mailboxes = ['custom' => ['host' => 'imap.example.com']];

    Config::set('imap', ['mailboxes' => $mailboxes]);

    (new ImapServiceProvider(App::getFacadeRoot()))->register();

    expect(Config::get('imap.mailboxes'))->toBe($mailboxes);
    expect(App::make(ImapManager::class)->mailbox('custom')->config('host'))
        ->toBe('imap.example.com');
});

it('preserves an explicitly empty mailbox configuration', function () {
    Config::set('imap', ['mailboxes' => []]);

    (new ImapServiceProvider(App::getFacadeRoot()))->register();

    expect(Config::get('imap.mailboxes'))->toBe([]);
});
