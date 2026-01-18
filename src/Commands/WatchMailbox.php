<?php

namespace DirectoryTree\ImapEngine\Laravel\Commands;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Events\MailboxWatchAttemptsExceeded;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\Laravel\Support\LoopInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Message;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class WatchMailbox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:watch {mailbox} {folder?} {--method=idle} {--with=} {--timeout=30} {--attempts=5} {--debug=false}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Watch a mailbox for new messages.';

    /**
     * Execute the console command.
     */
    public function handle(LoopInterface $loop): void
    {
        $mailbox = Imap::mailbox($name = $this->argument('mailbox'));

        $with = explode(',', $this->option('with'));

        $this->info("Watching mailbox [$name]...");

        $attempts = 0;

        $lastReceivedAt = null;

        $loop->run(function () use ($mailbox, $name, $with, &$attempts, &$lastReceivedAt) {
            try {
                $folder = $this->folder($mailbox);

                match ($this->option('method')) {
                    'idle' => $folder->idle(
                        new HandleMessageReceived($this, $attempts, $lastReceivedAt),
                        new ConfigureIdleQuery($with),
                        $this->option('timeout'),
                    ),
                    'poll' => $folder->poll(
                        new HandleMessageReceived($this, $attempts, $lastReceivedAt),
                        new ConfigureIdleQuery($with),
                        $this->option('timeout'),
                    ),
                };
            } catch (Exception $e) {
                if ($this->isMessageMissing($e)) {
                    return;
                }

                if ($this->isDisconnection($e)) {
                    sleep(2);

                    return;
                }

                if ($attempts >= $this->option('attempts')) {
                    $this->info("Exception: {$e->getMessage()}");

                    Event::dispatch(
                        new MailboxWatchAttemptsExceeded($name, $attempts, $e, $lastReceivedAt)
                    );

                    throw $e;
                }

                $attempts++;
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

    /**
     * Determine if the exception is due to a message missing error.
     */
    protected function isMessageMissing(Exception $e): bool
    {
        return Str::contains($e->getMessage(), [
            'no longer exist',
        ], true);
    }

    /**
     * Determine if the exception is caused by a disconnection.
     */
    protected function isDisconnection(Exception $e): bool
    {
        return Str::contains($e->getMessage(), [
            'connection reset by peer',
            'temporary system problem',
            'failed to fetch content',
            'connection failed',
            'empty response',
            'not connected',
            'no response',
            'broken pipe',
            'unavailable',
        ], true);
    }
}
