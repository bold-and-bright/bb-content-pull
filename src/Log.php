<?php

namespace BB\ContentPull;

use Kirby\Filesystem\F;
use Throwable;

/**
 * Appends pull runs to site/logs/content-pull.log.
 */
final class Log
{
    private string $run;

    public function __construct(
        private readonly string $file,
        private readonly string $actor,
    ) {
        $this->run = substr(bin2hex(random_bytes(4)), 0, 6);
    }

    public function write(string $message): void
    {
        $line = sprintf('[%s] [%s] [%s] %s', date('Y-m-d H:i:s'), $this->run, $this->actor, $message);

        try {
            F::append($this->file, $line . PHP_EOL);
        } catch (Throwable) {
            // A missing log must never break a pull
        }
    }
}
