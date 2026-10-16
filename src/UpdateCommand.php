<?php

namespace BB\ContentPull;

use Throwable;

/**
 * `kirby content-pull:update` – the same self-update as in the panel.
 * Works regardless of `bb.contentpull.updates`, which only controls the
 * panel: whoever has a shell may update anyway.
 */
final class UpdateCommand
{
    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'description' => 'Updates the content-pull plugin from its GitHub releases',
            'args' => [
                'check' => [
                    'longPrefix'  => 'check',
                    'description' => 'Only show installed and latest version',
                    'noValue'     => true,
                ],
                'version' => [
                    'longPrefix'  => 'version',
                    'description' => 'Install this version (e.g. 1.4.0) instead of the latest',
                ],
                'rollback' => [
                    'longPrefix'  => 'rollback',
                    'description' => 'Go back to the version before the last update',
                    'noValue'     => true,
                ],
            ],
            'command' => static function ($cli): void {
                \BB\ContentPull\UpdateCommand::run($cli);
            },
        ];
    }

    public static function run($cli): void
    {
        $kirby   = $cli->kirby();
        $updater = Updater::fromConfig($kirby);
        $log     = new Log(Plugin::logFile($kirby), 'cli');

        try {
            if ($cli->arg('rollback') === true) {
                $from = $updater->currentVersion();
                $to   = $updater->rollback();
                $log->write('ROLLBACK plugin ' . $from . ' -> ' . $to);
                $cli->success('Rolled back: ' . $from . ' -> ' . $to);
                return;
            }

            $status = $updater->status(refresh: true);

            if ($status['error'] !== null) {
                throw new \RuntimeException($status['error']);
            }

            $cli->out('Installed: ' . $status['current']);
            $cli->out('Latest:    ' . $status['latest']['version'] . ' (' . $status['latest']['url'] . ')');

            foreach ($status['warnings'] as $warning) {
                $cli->out('<yellow>! ' . $warning . '</yellow>');
            }

            $version = $cli->arg('version');

            if ($cli->arg('check') === true) {
                return;
            }

            if (empty($version) === true && $status['available'] === false) {
                $cli->info('Already up to date.');
                return;
            }

            $from = $status['current'];
            $to   = $updater->install(empty($version) === true ? null : (string) $version);
            $log->write('UPDATE plugin ' . $from . ' -> ' . $to);
            $cli->success('Updated: ' . $from . ' -> ' . $to . ' (rollback: kirby content-pull:update --rollback)');
        } catch (Throwable $e) {
            $cli->error($e->getMessage());
            exit(1);
        }
    }
}
