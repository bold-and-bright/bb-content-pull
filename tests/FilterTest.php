<?php

namespace BB\ContentPull\Tests;

use BB\ContentPull\Filter;
use BB\ContentPull\Manifest;
use BB\ContentPull\Options;
use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    /** A small multi-language content tree */
    private const FILES = [
        'content/site.de.txt',
        'content/site.en.txt',
        'content/logo.svg',
        'content/logo.svg.de.txt',
        'content/1_about/default.de.txt',
        'content/1_about/default.en.txt',
        'content/1_about/.lock',
        'content/1_about/team.jpg',
        'content/1_about/team.jpg.de.txt',
        'content/1_about/team.jpg.en.txt',
        'content/1_about/_changes/default.de.txt',
        'content/1_about/1_jobs/default.de.txt',
        'content/1_about/1_jobs/flyer.pdf',
        'content/1_about/_drafts/intern/default.de.txt',
        'content/2_aboutus/default.de.txt',
        'content/.DS_Store',
        'content/2_aboutus/.gitkeep',
        'accounts/abc/user.txt',
        'accounts/abc/index.php',
        'accounts/.logins',
    ];

    private function accepted(Options $options, array $languages = ['de', 'en']): array
    {
        $filter = new Filter($options, $languages);

        return array_values(array_filter(self::FILES, fn ($path) => $filter->accepts($path)));
    }

    public function testDefaultExcludesLocksClutterChangesAndAccounts(): void
    {
        $this->assertSame([
            'content/site.de.txt',
            'content/site.en.txt',
            'content/logo.svg',
            'content/logo.svg.de.txt',
            'content/1_about/default.de.txt',
            'content/1_about/default.en.txt',
            'content/1_about/team.jpg',
            'content/1_about/team.jpg.de.txt',
            'content/1_about/team.jpg.en.txt',
            'content/1_about/1_jobs/default.de.txt',
            'content/1_about/1_jobs/flyer.pdf',
            'content/1_about/_drafts/intern/default.de.txt',
            'content/2_aboutus/default.de.txt',
        ], $this->accepted(new Options()));
    }

    public function testScopeTexts(): void
    {
        $paths = $this->accepted(new Options(scope: 'texts'));

        $this->assertContains('content/1_about/team.jpg.de.txt', $paths, 'file metadata counts as text');
        $this->assertNotContains('content/1_about/team.jpg', $paths);
        $this->assertNotContains('content/logo.svg', $paths);
        $this->assertCount(10, $paths);
    }

    public function testScopeMedia(): void
    {
        $this->assertSame([
            'content/logo.svg',
            'content/1_about/team.jpg',
            'content/1_about/1_jobs/flyer.pdf',
        ], $this->accepted(new Options(scope: 'media')));
    }

    public function testPageWithoutSubpages(): void
    {
        $this->assertSame([
            'content/1_about/default.de.txt',
            'content/1_about/default.en.txt',
            'content/1_about/team.jpg',
            'content/1_about/team.jpg.de.txt',
            'content/1_about/team.jpg.en.txt',
        ], $this->accepted(new Options(page: 'about')));
    }

    public function testPageRecursiveIncludesSubpagesAndDraftsButNotLookalikes(): void
    {
        $paths = $this->accepted(new Options(page: 'about', recursive: true));

        $this->assertContains('content/1_about/1_jobs/flyer.pdf', $paths);
        $this->assertContains('content/1_about/_drafts/intern/default.de.txt', $paths);
        $this->assertNotContains('content/2_aboutus/default.de.txt', $paths, '"aboutus" is no child of "about"');
        $this->assertNotContains('content/site.de.txt', $paths);
        $this->assertCount(8, $paths);
    }

    public function testDraftPage(): void
    {
        $this->assertSame(
            ['content/1_about/_drafts/intern/default.de.txt'],
            $this->accepted(new Options(page: 'about/intern'))
        );
    }

    public function testSitePage(): void
    {
        $this->assertSame([
            'content/site.de.txt',
            'content/site.en.txt',
            'content/logo.svg',
            'content/logo.svg.de.txt',
        ], $this->accepted(Options::fromArray(['page' => 'site'])));

        // site + recursive = everything
        $this->assertCount(13, $this->accepted(Options::fromArray(['page' => 'site', 'recursive' => true])));
    }

    public function testLanguagesOnlyFilterTextFiles(): void
    {
        $paths = $this->accepted(new Options(languages: ['en']));

        $this->assertContains('content/site.en.txt', $paths);
        $this->assertContains('content/1_about/team.jpg.en.txt', $paths);
        $this->assertContains('content/1_about/team.jpg', $paths, 'media is not language specific');
        $this->assertNotContains('content/site.de.txt', $paths);
        $this->assertNotContains('content/1_about/team.jpg.de.txt', $paths);
    }

    public function testCombinedFilters(): void
    {
        $this->assertSame(
            ['content/1_about/default.en.txt', 'content/1_about/team.jpg.en.txt'],
            $this->accepted(new Options(scope: 'texts', page: 'about', languages: ['en']))
        );
    }

    public function testIncludeChanges(): void
    {
        $this->assertContains(
            'content/1_about/_changes/default.de.txt',
            $this->accepted(new Options(page: 'about', includeChanges: true))
        );
    }

    public function testAccountsAreAllOrNothing(): void
    {
        $paths = $this->accepted(new Options(scope: 'media', page: 'about', accounts: true));

        $this->assertContains('accounts/abc/user.txt', $paths);
        $this->assertContains('accounts/abc/index.php', $paths);
        $this->assertContains('content/1_about/team.jpg', $paths);
    }

    public function testScopeAccountsPullsOnlyAccounts(): void
    {
        $options = new Options(scope: 'accounts');

        $this->assertTrue($options->includesAccounts());
        $this->assertSame(
            ['accounts/abc/user.txt', 'accounts/abc/index.php'],
            $this->accepted($options),
            'no content, no login throttle file'
        );
        $this->assertSame(['scope' => 'accounts'], $options->toQuery());
    }

    public function testManifestScanAppliesFilterAndHashes(): void
    {
        $root = sys_get_temp_dir() . '/bbpull-filter-' . bin2hex(random_bytes(4));

        foreach (self::FILES as $path) {
            @mkdir(dirname($root . '/' . $path), 0777, true);
            file_put_contents($root . '/' . $path, $path);
        }

        try {
            $roots   = ['content' => $root . '/content', 'accounts' => $root . '/accounts'];
            $entries = Manifest::scan($roots, new Filter(new Options(scope: 'texts', page: 'about'), ['de', 'en']));

            $this->assertSame([
                'content/1_about/default.de.txt',
                'content/1_about/default.en.txt',
                'content/1_about/team.jpg.de.txt',
                'content/1_about/team.jpg.en.txt',
            ], array_keys($entries));

            $entry = $entries['content/1_about/default.de.txt'];
            $this->assertSame(sha1('content/1_about/default.de.txt'), $entry['sha1']);
            $this->assertSame(strlen('content/1_about/default.de.txt'), $entry['size']);

            $unhashed = Manifest::scan($roots, new Filter(new Options(scope: 'media')), hash: false);
            $this->assertNull($unhashed['content/logo.svg']['sha1']);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testRemoteManifestDropsUnsafePaths(): void
    {
        $rejected = [];
        $entries  = Manifest::fromRemote([
            ['path' => 'content/site.de.txt', 'sha1' => str_repeat('a', 40), 'size' => 3, 'mtime' => 1],
            ['path' => 'content/../site/config/config.php', 'sha1' => str_repeat('b', 40), 'size' => 3],
            ['path' => 'accounts/x/index.php', 'sha1' => str_repeat('c', 40), 'size' => 3],
            ['path' => 'content/a.txt', 'sha1' => 'not-a-hash', 'size' => 1],
            'garbage',
        ], ['content'], $rejected);

        $this->assertSame(['content/a.txt', 'content/site.de.txt'], array_keys($entries));
        $this->assertNull($entries['content/a.txt']['sha1']);
        $this->assertSame(['content/../site/config/config.php', 'accounts/x/index.php', '(invalid entry)'], $rejected);
    }
}
