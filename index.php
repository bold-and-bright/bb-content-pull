<?php

use Kirby\Cms\App as Kirby;

// Without Composer (plugin copied into site/plugins) register our own autoloader
if (class_exists('BB\\ContentPull\\Puller') === false) {
    spl_autoload_register(function (string $class): void {
        $prefix = 'BB\\ContentPull\\';

        if (str_starts_with($class, $prefix) === true) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file) === true) {
                require $file;
            }
        }
    });
}

/**
 * bb/content-pull — pull content from a source environment.
 *
 * Kirby rebinds route actions, so every action calls a static method by its
 * fully qualified class name instead of using $this/self.
 */
Kirby::plugin('bb/content-pull', [
    // Source side, only answering with bb.contentpull.serve => true
    'routes' => [
        [
            'pattern' => 'bb-content-pull/manifest',
            'method'  => 'GET',
            'action'  => fn () => \BB\ContentPull\Server::manifest(),
        ],
        [
            'pattern' => 'bb-content-pull/file',
            'method'  => 'GET',
            'action'  => fn () => \BB\ContentPull\Server::file(),
        ],
        [
            'pattern' => 'bb-content-pull/archive',
            'method'  => 'GET',
            'action'  => fn () => \BB\ContentPull\Server::archive(),
        ],
        [
            // Kirby credentials -> session token
            'pattern' => 'bb-content-pull/login',
            'method'  => 'POST',
            'action'  => fn () => \BB\ContentPull\Server::login(),
        ],
    ],

    // Target side
    'commands' => [
        'content:pull'        => \BB\ContentPull\Command::definition(),
        'content-pull:update' => \BB\ContentPull\UpdateCommand::definition(),
    ],

    // Optional self-update from GitHub releases (bb.contentpull.updates)
    'areas' => [
        'content-pull-update' => fn (Kirby $kirby) => \BB\ContentPull\UpdateApi::area($kirby),
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'content-pull/update/status',
                'method'  => 'GET',
                'action'  => fn () => \BB\ContentPull\UpdateApi::status(),
            ],
            [
                'pattern' => 'content-pull/update/install',
                'method'  => 'POST',
                'action'  => fn () => \BB\ContentPull\UpdateApi::install(),
            ],
            [
                'pattern' => 'content-pull/update/rollback',
                'method'  => 'POST',
                'action'  => fn () => \BB\ContentPull\UpdateApi::rollback(),
            ],
        ],
    ],
]);
