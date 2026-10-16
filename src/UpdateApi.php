<?php

namespace BB\ContentPull;

use Kirby\Cms\App;
use Kirby\Exception\PermissionException;

/**
 * Panel integration and API (/api/content-pull/update/…) for the optional
 * self-update. Admins only, and only with `bb.contentpull.updates => true`.
 *
 * No sidebar entry: the plugin's row in the plugin table of /panel/system
 * shows the update status, its dropdown links to the update view, which is
 * part of the system area as well.
 */
final class UpdateApi
{
    public static function allowed(App $kirby): bool
    {
        return Updater::enabled() === true && $kirby->user()?->isAdmin() === true;
    }

    /**
     * Extends the core "system" area: wraps Kirby's own system view action
     * and replaces the version cell of this plugin in the plugin table.
     * Returns nothing (= core unchanged) if updates are off or the core view
     * cannot be found.
     */
    public static function systemArea(App $kirby): array
    {
        if (self::allowed($kirby) === false) {
            return [];
        }

        $file = $kirby->root('kirby') . '/config/areas/system/views.php';

        if (is_file($file) === false) {
            return [];
        }

        $views = require $file;

        foreach ((array) $views as $key => $view) {
            $core = $view['action'] ?? null;

            if (($view['pattern'] ?? null) !== 'system' || is_callable($core) === false) {
                continue;
            }

            // Kirby rebinds route actions: no self::/$this in these closures,
            // only fully qualified names (otherwise a silent 404)
            $view['action'] = function (...$args) use ($core, $kirby) {
                return \BB\ContentPull\UpdateApi::decorateSystemView($core(...$args), $kirby);
            };

            return ['views' => [
                $key                  => $view,
                // Lives in the system area: sidebar highlights "System",
                // breadcrumb reads System › Content Pull Update
                'content-pull-update' => [
                    'pattern' => 'content-pull-update',
                    'action'  => function () use ($kirby) {
                        \BB\ContentPull\UpdateApi::guard($kirby);

                        return [
                            'component'  => 'k-content-pull-update-view',
                            'title'      => 'Content Pull Update',
                            'breadcrumb' => [
                                ['label' => 'Content Pull Update', 'link' => 'content-pull-update'],
                            ],
                        ];
                    },
                ],
            ]];
        }

        return [];
    }

    /**
     * Same shape as Kirby's own UpdateStatus::toArray(), so the panel renders
     * it with its regular update status cell.
     */
    public static function decorateSystemView(mixed $result, App $kirby): mixed
    {
        if (is_array($result['props']['plugins'] ?? null) === false) {
            return $result;
        }

        $status  = Updater::fromConfig($kirby)->status();
        $latest  = $status['latest']['version'] ?? null;
        $version = match (true) {
            $status['error'] !== null   => ['label' => 'Update-Prüfung fehlgeschlagen', 'theme' => 'notice', 'icon' => 'alert'],
            $status['available']        => ['label' => 'Update ' . $latest . ' verfügbar', 'theme' => 'info', 'icon' => 'info'],
            default                     => ['label' => 'Aktuell', 'theme' => 'positive', 'icon' => 'check'],
        };

        foreach ($result['props']['plugins'] as $index => $plugin) {
            if (($plugin['name']['text'] ?? null) !== 'bb/content-pull') {
                continue;
            }

            $result['props']['plugins'][$index]['version'] = $version + [
                'currentVersion' => $status['current'],
                'latestVersion'  => $latest ?? '?',
                'pluginName'     => 'bb/content-pull',
                'url'            => $kirby->url('panel') . '/content-pull-update',
            ];
        }

        return $result;
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

    public static function guard(App $kirby): void
    {
        if (self::allowed($kirby) === false) {
            throw new PermissionException('Plugin updates are disabled (bb.contentpull.updates) or you are not an admin');
        }
    }
}
