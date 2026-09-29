<?php

namespace DirectoryTree\ImapEngine\Laravel\Commands;

use DirectoryTree\ImapEngine\MessageData;
use DirectoryTree\ImapEngine\MessageQueryInterface;

class ConfigureIdleQuery
{
    /**
     * Constructor.
     */
    public function __construct(
        protected array $with = []
    ) {}

    /**
     * Configure the query.
     */
    public function __invoke(MessageQueryInterface $query): MessageQueryInterface
    {
        if (in_array('flags', $this->with)) {
            $query->with(MessageData::flags());
        }

        if (in_array('body', $this->with)) {
            $query->with(MessageData::text()->peek());
        }

        if (in_array('headers', $this->with)) {
            $query->with(MessageData::headers()->peek());
        }

        return $query;
    }
}
