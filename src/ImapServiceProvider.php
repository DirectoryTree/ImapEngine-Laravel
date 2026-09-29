<?php

namespace DirectoryTree\ImapEngine\Laravel;

use DirectoryTree\ImapEngine\Laravel\Support\Loop;
use DirectoryTree\ImapEngine\Laravel\Support\LoopInterface;
use Illuminate\Support\ServiceProvider;

class ImapServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/imap.php', 'imap');

        $this->app->singleton(ImapManager::class, function () {
            return new ImapManager(config('imap', []));
        });

        $this->app->bind(LoopInterface::class, Loop::class);
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\WatchMailbox::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../config/imap.php' => config_path('imap.php'),
        ]);
    }
}
