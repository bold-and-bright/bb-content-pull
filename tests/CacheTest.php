<?php

namespace BB\ContentPull\Tests;

use BB\ContentPull\Filter;
use BB\ContentPull\HashCache;
use BB\ContentPull\Manifest;
use BB\ContentPull\ObjectCache;
use BB\ContentPull\Options;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/bbpull-cache-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/content/1_about', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    public function testHashCacheReusesHashesOfUnchangedFiles(): void
    {
        $file = $this->tmp . '/content/1_about/default.txt';
        file_put_contents($file, 'one');
        touch($file, 1000);

        $cache = new HashCache($this->tmp . '/state/hashes.json');
        $this->assertSame(sha1('one'), $cache->get('content/1_about/default.txt', $file, 3, 1000));
        $cache->save();

        // Same size and mtime: the cached hash wins, the file is not read
        file_put_contents($file, 'two');
        touch($file, 1000);

        $reloaded = new HashCache($this->tmp . '/state/hashes.json');
        $this->assertSame(sha1('one'), $reloaded->get('content/1_about/default.txt', $file, 3, 1000));

        // Changed mtime: hashed again
        $this->assertSame(sha1('two'), $reloaded->get('content/1_about/default.txt', $file, 3, 2000));
    }

    public function testManifestScanUsesHashCache(): void
    {
        $file = $this->tmp . '/content/1_about/default.txt';
        file_put_contents($file, 'one');

        $roots = ['content' => $this->tmp . '/content'];
        $json  = $this->tmp . '/state/hashes.json';

        Manifest::scan($roots, new Filter(new Options()), cache: new HashCache($json));

        $this->assertFileExists($json);
        $this->assertArrayHasKey('content/1_about/default.txt', json_decode(file_get_contents($json), true));
    }

    public function testObjectCacheStoresByHash(): void
    {
        $file = $this->tmp . '/photo.jpg';
        file_put_contents($file, 'pixels');
        $sha1 = sha1('pixels');

        $cache = new ObjectCache($this->tmp . '/objects');
        $this->assertFalse($cache->has($sha1));
        $this->assertFalse($cache->has(null));

        $cache->store($sha1, $file);

        $this->assertTrue($cache->has($sha1));
        $this->assertSame($this->tmp . '/objects/' . substr($sha1, 0, 2) . '/' . $sha1, $cache->path($sha1));
        $this->assertSame('pixels', file_get_contents($cache->path($sha1)));
        $this->assertSame([], glob($this->tmp . '/objects/*/*.tmp'), 'no temp files left behind');

        $cache->forget($sha1);
        $this->assertFalse($cache->has($sha1));
    }
}
