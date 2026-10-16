<?php

namespace BB\ContentPull;

use Kirby\Cms\App;
use Kirby\Exception\PermissionException;

/**
 * Panel area and API (/api/content-pull/update/…) for the optional
 * self-update. Admins only, and only with `bb.contentpull.updates => true`.
 */
final class UpdateApi
{
    public static function allowed(App $kirby): bool
    {
        return Updater::enabled() === true && $kirby->user()?->isAdmin() === true;
    }

    /**
     * The menu entry only shows up when there is something to do.
     */
    public static function menu(App $kirby): bool
    {
        if (self::allowed($kirby) === false) {
            return false;
        }

        $status = Updater::fromConfig($kirby)->status();

        return $status['available'] === true || $status['backup'] !== null;
    }

    public static function area(App $kirby): array
    {
        return [
            'label' => 'Content Pull Update',
            'icon'  => 'download',
            'menu'  => fn () => self::menu($kirby),
            'link'  => 'content-pull-update',
            'views' => [
                [
                    'pattern' => 'content-pull-update',
                    'action'  => function () use ($kirby) {
                        self::guard($kirby);

                        return [
                            'component' => 'k-content-pull-update-view',
                            'title'     => 'Content Pull Update',
                        ];
                    },
                ],
            ],
        ];
    }

    public static function status(): array
    {
        $kirby = App::instance();
        self::guard($kirby);

        return Updater::fromConfig($kirby)->status(refresh: $kirby->request()->get('refresh') === '1');
    }

    public static function install(): array
    {
        $kirby = App::instance();
        self::guard($kirby);

        @set_time_limit(300);

        $updater = Updater::fromConfig($kirby);
        $from    = $updater->currentVersion();
        $to      = $updater->install();

        (new Log(Plugin::logFile($kirby), 'panel:' . $kirby->user()?->email()))->write('UPDATE plugin ' . $from . ' -> ' . $to);

        return ['from' => $from, 'to' => $to];
    }

    public static function rollback(): array
    {
        $kirby = App::instance();
        self::guard($kirby);

        $updater = Updater::fromConfig($kirby);
        $from    = $updater->currentVersion();
        $to      = $updater->rollback();

        (new Log(Plugin::logFile($kirby), 'panel:' . $kirby->user()?->email()))->write('ROLLBACK plugin ' . $from . ' -> ' . $to);

        return ['from' => $from, 'to' => $to];
    }

    private static function guard(App $kirby): void
    {
        if (self::allowed($kirby) === false) {
            throw new PermissionException('Plugin updates are disabled (bb.contentpull.updates) or you are not an admin');
        }
    }
}
