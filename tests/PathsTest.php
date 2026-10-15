<?php

namespace BB\ContentPull\Tests;

use BB\ContentPull\Paths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/bbpull-paths-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/content/1_about', 0777, true);
        mkdir($this->tmp . '/outside', 0777, true);
        file_put_contents($this->tmp . '/content/1_about/default.de.txt', 'Title: About');
        file_put_contents($this->tmp . '/outside/secret.txt', 'secret');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    public static function validPaths(): array
    {
        return [
            ['content/site.de.txt'],
            ['content/1_about/default.de.txt'],
            ['content/1_about/team photo.jpg'],
            ['content/1_about/_drafts/jobs/job.en.txt'],
            ['content/1_about/..hidden.txt'],
        ];
    }

    #[DataProvider('validPaths')]
    public function testNormalizeAcceptsValidPaths(string $path): void
    {
        $this->assertSame($path, Paths::normalize($path));
    }

    public static function invalidPaths(): array
    {
        return [
            'empty'               => [''],
            'root only'           => ['content'],
            'absolute'            => ['/etc/passwd'],
            'absolute in content' => ['/content/site.txt'],
            'parent'              => ['content/../site/config/config.php'],
            'parent at end'       => ['content/1_about/..'],
            'nested parent'       => ['content/1_about/../../accounts/x/index.php'],
            'current dir'         => ['content/./site.txt'],
            'double slash'        => ['content//site.txt'],
            'trailing slash'      => ['content/1_about/'],
            'backslash'           => ['content\\..\\site.txt'],
            'null byte'           => ["content/site.txt\0.jpg"],
            'newline'             => ["content/site\n.txt"],
            'windows drive'       => ['C:/content/site.txt'],
            'other root'          => ['site/config/config.php'],
            'accounts not allowed'=> ['accounts/abc/index.php'],
            'prefix lookalike'    => ['contents/site.txt'],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function testNormalizeRejectsInvalidPaths(string $path): void
    {
        $this->assertNull(Paths::normalize($path));
    }

    public function testNormalizeAllowsAccountsOnlyWhenRequested(): void
    {
        $this->assertNull(Paths::normalize('accounts/abc/user.txt'));
        $this->assertSame('accounts/abc/user.txt', Paths::normalize('accounts/abc/user.txt', ['content', 'accounts']));
    }

    public function testResolveMapsIntoRoot(): void
    {
        $roots = ['content' => $this->tmp . '/content'];

        $this->assertSame(
            realpath($this->tmp . '/content') . '/1_about/default.de.txt',
            Paths::resolve('content/1_about/default.de.txt', $roots)
        );

        // Not yet existing files and folders are fine
        $this->assertSame(
            realpath($this->tmp . '/content') . '/2_new/sub/default.de.txt',
            Paths::resolve('content/2_new/sub/default.de.txt', $roots)
        );
    }

    public function testResolveRejectsTraversalAndUnknownRoots(): void
    {
        $roots = ['content' => $this->tmp . '/content'];

        $this->assertNull(Paths::resolve('content/../outside/secret.txt', $roots));
        $this->assertNull(Paths::resolve('accounts/x/user.txt', $roots));
    }

    public function testResolveRejectsSymlinksLeavingTheRoot(): void
    {
        symlink($this->tmp . '/outside', $this->tmp . '/content/link');
        symlink($this->tmp . '/outside/secret.txt', $this->tmp . '/content/secret.txt');

        $roots = ['content' => $this->tmp . '/content'];

        $this->assertNull(Paths::resolve('content/link/secret.txt', $roots));
        $this->assertNull(Paths::resolve('content/link/new.txt', $roots));
        $this->assertNull(Paths::resolve('content/secret.txt', $roots));
    }

    public function testExcludedFiles(): void
    {
        $this->assertTrue(Paths::isExcluded('content/1_about/.lock'));
        $this->assertTrue(Paths::isExcluded('content/.DS_Store'));
        $this->assertTrue(Paths::isExcluded('content/1_about/.gitkeep'));
        $this->assertTrue(Paths::isExcluded('content/1_about/.bbpull-abc123.tmp'));
        $this->assertTrue(Paths::isExcluded('content/1_about/_changes/default.de.txt'));
        $this->assertFalse(Paths::isExcluded('content/1_about/_changes/default.de.txt', includeChanges: true));
        $this->assertFalse(Paths::isExcluded('content/1_about/default.de.txt'));
        $this->assertFalse(Paths::isExcluded('content/1_about/lock.jpg'));
    }

    public static function pageIds(): array
    {
        return [
            ['content/site.de.txt', ''],
            ['content/logo.svg', ''],
            ['content/1_about/default.de.txt', 'about'],
            ['content/about/team.jpg', 'about'],
            ['content/1_about/3_team/member.en.txt', 'about/team'],
            ['content/1_about/_drafts/jobs/job.de.txt', 'about/jobs'],
            ['content/_drafts/new/default.de.txt', 'new'],
            ['content/20240101_blog/_changes/post.de.txt', 'blog'],
            ['accounts/abc/user.txt', null],
        ];
    }

    #[DataProvider('pageIds')]
    public function testPageId(string $path, ?string $id): void
    {
        $this->assertSame($id, Paths::pageId($path));
    }

    public function testLanguage(): void
    {
        $this->assertSame('de', Paths::language('content/1_about/default.de.txt'));
        $this->assertSame('en', Paths::language('content/1_about/photo.jpg.en.txt'));
        $this->assertNull(Paths::language('content/1_about/default.txt'));
        $this->assertNull(Paths::language('content/1_about/photo.jpg.txt'));
        $this->assertNull(Paths::language('content/1_about/photo.jpg'));

        // Known languages avoid false positives like Illustrator files
        $this->assertSame('ai', Paths::language('content/1_about/logo.ai.txt'));
        $this->assertNull(Paths::language('content/1_about/logo.ai.txt', ['de', 'en']));
        $this->assertSame('de-at', Paths::language('content/1_about/default.de-at.txt', ['de', 'de-at']));
    }

    public function testNormalizePageId(): void
    {
        $this->assertNull(Paths::normalizePageId(null));
        $this->assertSame('', Paths::normalizePageId('site'));
        $this->assertSame('', Paths::normalizePageId('/'));
        $this->assertSame('about/team', Paths::normalizePageId('/about/team/'));
    }
}
