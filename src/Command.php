<?php

namespace BB\ContentPull;

use Throwable;

/**
 * `kirby content:pull` (getkirby/cli).
 */
final class Command
{
    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $flag = fn (string $name, string $description) => [
            'longPrefix'  => $name,
            'description' => $description,
            'noValue'     => true,
        ];

        return [
            'description' => 'Pulls content from the source environment (bb.contentpull.source)',
            'args' => [
                'scope' => [
                    'longPrefix'   => 'scope',
                    'description'  => 'texts (all *.txt incl. file metadata), media (everything else), all, or accounts (only site/accounts)',
                    'defaultValue' => 'all',
                ],
                'page' => [
                    'longPrefix'  => 'page',
                    'description' => 'Only this page folder (page id, e.g. about/team; "site" for site files)',
                ],
                'recursive'       => $flag('recursive', 'With --page: include all subpages'),
                'lang' => [
                    'longPrefix'  => 'lang',
                    'description' => 'Only text files of these languages, e.g. de,en',
                ],
                'missing-only'    => $flag('missing-only', 'Only fetch files that do not exist locally'),
                'max-size' => [
                    'longPrefix'  => 'max-size',
                    'description' => 'Skip media files larger than this, e.g. 20M',
                ],
                'delete'          => $flag('delete', 'Delete local files in scope that do not exist on the source'),
                'dry-run'         => $flag('dry-run', 'Only list what would change'),
                'accounts'        => $flag('accounts', 'Also sync site/accounts (source needs bb.contentpull.accounts)'),
                'include-changes' => $flag('include-changes', 'Include Kirby 5 _changes (unsaved panel changes)'),
                'archive'         => $flag('archive', 'Download the filtered set as one ZIP (large media sets)'),
                'json'            => $flag('json', 'Machine-readable output: one JSON document on stdout'),
                'user' => [
                    'longPrefix'  => 'user',
                    'description' => 'Log in with this Kirby user (email) on the source instead of the token; the password is asked for if not given',
                ],
                'password' => [
                    'longPrefix'  => 'password',
                    'description' => 'Password for --user (prefer the prompt: arguments end up in the shell history)',
                ],
                'login'           => $flag('login', 'Ask for Kirby email and password of the source'),
            ],
            'command' => static function ($cli): void {
                \BB\ContentPull\Command::run($cli);
            },
        ];
    }

    public static function run($cli): void
    {
        @set_time_limit(0);

        $json = $cli->arg('json') === true;

        // The Kirby CLI exits with 0 even after an exception; scripts, cron
        // jobs and agents need to see failed pulls
        try {
            $options = self::options($cli);
            $puller  = Puller::fromConfig($cli->kirby(), 'cli', self::credentials($cli));
            $plan    = $json === true ? $puller->run($options) : self::pull($cli, $puller, $options);
        } catch (Throwable $e) {
            $json === true
                ? self::json(['ok' => false, 'error' => $e->getMessage()])
                : $cli->error($e->getMessage());

            exit(1);
        }

        $failed = count($plan['result']['failed'] ?? []);

        if ($json === true) {
            self::json(['ok' => $failed === 0, 'error' => null] + $plan);
        } elseif ($failed > 0) {
            $cli->error($failed . ' file(s) failed — see above and site/logs/content-pull.log');
        }

        if ($failed > 0) {
            exit(1);
        }
    }

    /**
     * Kirby credentials from --user/--password, missing parts asked for on
     * the terminal; null means: use the configured token.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function credentials($cli): ?array
    {
        $user     = $cli->arg('user');
        $password = $cli->arg('password');

        if ($cli->arg('login') !== true && empty($user) === true && empty($password) === true) {
            return null;
        }

        if (empty($user) === true) {
            $user = self::ask('Kirby user (email) on the source: ');
        }

        if (empty($password) === true) {
            $password = self::ask('Password for ' . $user . ': ', hidden: true);
        }

        if ($user === '' || $password === '') {
            throw new \RuntimeException('Email and password are required for --user/--login');
        }

        return [$user, $password];
    }

    /**
     * Prompts on stderr, so --json output on stdout stays clean.
     */
    private static function ask(string $prompt, bool $hidden = false): string
    {
        if (stream_isatty(STDIN) === false) {
            throw new \RuntimeException('No terminal to ask for credentials – pass --user and --password');
        }

        // Echo off *before* the prompt appears, so nothing typed early is shown
        if ($hidden === true) {
            shell_exec('stty -echo < /dev/tty 2>/dev/null');
        }

        fwrite(STDERR, $prompt);

        try {
            $answer = fgets(STDIN);
        } finally {
            if ($hidden === true) {
                shell_exec('stty echo < /dev/tty 2>/dev/null');
                fwrite(STDERR, PHP_EOL);
            }
        }

        return trim((string) $answer);
    }

    private static function options($cli): Options
    {
        return Options::fromArray([
            'scope'           => $cli->arg('scope'),
            'page'            => $cli->arg('page'),
            'recursive'       => $cli->arg('recursive'),
            'lang'            => $cli->arg('lang'),
            'missing-only'    => $cli->arg('missing-only'),
            'max-size'        => $cli->arg('max-size'),
            'delete'          => $cli->arg('delete'),
            'dry-run'         => $cli->arg('dry-run'),
            'accounts'        => $cli->arg('accounts'),
            'include-changes' => $cli->arg('include-changes'),
            'archive'         => $cli->arg('archive'),
        ]);
    }

    /**
     * Human-readable run with live progress.
     */
    private static function pull($cli, Puller $puller, Options $options): array
    {
        $cli->out('Source:  ' . $puller->client()->source());
        $cli->out('Options: ' . $options->describe());
        $cli->br();

        $plan = $puller->run($options, function (string $action, string $path, ?string $error) use ($cli): void {
            match ($action) {
                'write'  => $cli->out('<green>+</green> ' . $path),
                'delete' => $cli->out('<red>-</red> ' . $path),
                'fail'   => $cli->out('<red>! ' . $path . ': ' . $error . '</red>'),
            };
        });

        if ($options->dryRun === true) {
            self::printDiff($cli, $plan['diff']);
        }

        $summary = $plan['summary'];

        $cli->br();
        $cli->out(sprintf(
            '%d new, %d changed, %d deleted, %d skipped, %d unchanged (%s to transfer)',
            $summary['new'],
            $summary['changed'],
            $summary['deleted'],
            $summary['skipped'],
            $summary['unchanged'],
            Size::format($summary['bytes'])
        ));

        if ($options->dryRun === true) {
            $cli->info('Dry run — nothing was changed.');
        } elseif ($plan['result']['written'] === [] && $plan['result']['deleted'] === []) {
            $cli->info($plan['result']['failed'] === [] ? 'Already up to date.' : 'Nothing was changed.');
        } elseif ($plan['result']['failed'] === []) {
            $cli->success(
                'Content pulled, caches cleared.' .
                ($plan['result']['cached'] > 0 ? ' ' . $plan['result']['cached'] . ' file(s) from the shared cache.' : '')
            );
        }

        return $plan;
    }

    /**
     * Written directly to stdout: CLImate would interpret <tags> in paths.
     */
    private static function json(array $data): void
    {
        fwrite(STDOUT, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    private static function printDiff($cli, array $diff): void
    {
        foreach ($diff['new'] as $entry) {
            $cli->out('<green>+ new</green>     ' . $entry['path'] . ' (' . Size::format($entry['size']) . ')');
        }

        foreach ($diff['changed'] as $entry) {
            $cli->out('<yellow>~ changed</yellow> ' . $entry['path'] . ' (' . Size::format($entry['size']) . ')');
        }

        foreach ($diff['deleted'] as $entry) {
            $cli->out('<red>- deleted</red> ' . $entry['path']);
        }

        foreach ($diff['skipped'] as $entry) {
            $cli->out('<dark_gray>· skipped</dark_gray> ' . $entry['path'] . ' (' . $entry['reason'] . ($entry['reason'] === 'size' ? ', ' . Size::format($entry['size']) : '') . ')');
        }
    }
}
