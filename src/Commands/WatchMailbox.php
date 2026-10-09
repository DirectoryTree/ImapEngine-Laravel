<?php

namespace DirectoryTree\ImapEngine\Laravel\Commands;

use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use DirectoryTree\ImapEngine\Exceptions\ImapStreamException;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Laravel\Events\MailboxWatchAttemptsExceeded;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\Laravel\Support\LoopInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Exception\InvalidOptionException;

class WatchMailbox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:watch
                            {mailbox : The mailbox to watch}
                            {folder? : The folder to watch}
                            {--method=idle : The watch method (idle, poll, or events)}
                            {--with= : Comma-separated message parts to fetch with idle or poll (flags, body, headers)}
                            {--timeout=30 : The IDLE renewal interval or polling frequency in seconds}
                            {--attempts=5 : Maximum connection retries before a message or mailbox update is received}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Watch a mailbox for new messages or mailbox events.';

    /**
     * Execute the console command.
     */
    public function handle(LoopInterface $loop): void
    {
        if (! in_array($method = $this->option('method'), ['idle', 'poll', 'events'])) {
            throw new InvalidOptionException("Invalid method [{$method}]. Valid options are [idle, poll, events].");
        }

        $timeout = (int) $this->option('timeout');

        $retries = (int) $this->option('attempts');

        $with = array_filter(
            array_map('trim', explode(',', $this->option('with'))),
            filled(...),
        );

        if ($invalid = array_diff($with, ['flags', 'body', 'headers'])) {
            throw new InvalidOptionException('Invalid message parts ['.implode(', ', $invalid).']. Valid options are [flags, body, headers].');
        }

        $mailbox = Imap::mailbox($name = $this->argument('mailbox'));

        $this->info("Watching mailbox [$name]...");

        $attempts = 0;

        $lastReceivedAt = null;

        $handler = $method === 'events'
            ? new HandleMailboxEventReceived($this, $name, $attempts, $lastReceivedAt)
            : new HandleMessageReceived($this, $name, $attempts, $lastReceivedAt);

        $loop->run(function () use ($mailbox, $name, $method, $with, $timeout, $retries, $handler, &$attempts, &$lastReceivedAt) {
            $receiving = false;

            $callback = function (MessageInterface|EventInterface $event) use ($handler, &$receiving) {
                $receiving = true;

                $handler($event);

                $receiving = false;
            };

            try {
                $folder = $this->folder($mailbox);

                if ($method === 'events') {
                    $folder->events($callback, $timeout);
                } else {
                    $folder->{$method}($callback, new ConfigureIdleQuery($with), $timeout);
                }
            } catch (ImapConnectionException|ImapStreamException $e) {
                // Listener failures must not restart the watcher, even for IMAP errors.
                if ($receiving) {
                    throw $e;
                }

                $mailbox->disconnect();

                if ($attempts >= $retries) {
                    $this->error("Exception: {$e->getMessage()}");

                    Event::dispatch(
                        new MailboxWatchAttemptsExceeded($name, $attempts, $e, $lastReceivedAt)
                    );

                    throw $e;
                }

                $attempts++;

                $this->warn("Connection failed. Retrying [$attempts/$retries] in 2 seconds.");

                Sleep::for(2)->seconds();
            }
        });
    }

    /**
     * Get the mailbox folder to idle.
     */
    protected function folder(MailboxInterface $mailbox): FolderInterface
    {
        return ($folder = $this->argument('folder'))
             ? $mailbox->folders()->findOrFail($folder)
             : $mailbox->inbox();
    }
}
