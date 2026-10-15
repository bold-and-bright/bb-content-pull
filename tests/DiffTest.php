<?php

namespace BB\ContentPull\Tests;

use BB\ContentPull\Diff;
use BB\ContentPull\Options;
use PHPUnit\Framework\TestCase;

final class DiffTest extends TestCase
{
    private static function entry(string $path, string $contents): array
    {
        return ['path' => $path, 'sha1' => sha1($contents), 'size' => strlen($contents), 'mtime' => 1];
    }

    private function compute(Options $options): array
    {
        $remote = [
            'content/a/default.de.txt' => self::entry('content/a/default.de.txt', 'same'),
            'content/a/changed.de.txt' => self::entry('content/a/changed.de.txt', 'new!'),
            'content/a/grown.de.txt'   => self::entry('content/a/grown.de.txt', 'longer now'),
            'content/a/new.jpg'        => self::entry('content/a/new.jpg', str_repeat('x', 2000)),
            'content/a/huge.mp4'       => self::entry('content/a/huge.mp4', str_repeat('x', 5000)),
        ];

        $localContents = [
            'content/a/default.de.txt' => 'same',
            'content/a/changed.de.txt' => 'old!',
            'content/a/grown.de.txt'   => 'short',
            'content/a/gone.jpg'       => 'bye',
        ];

        $local = [];
        foreach ($localContents as $path => $contents) {
            $local[$path] = self::entry($path, $contents);
            $local[$path]['sha1'] = null;
        }

        $hashed = [];
        $diff = Diff::compute($remote, $local, $options, function (string $path) use ($localContents, &$hashed) {
            $hashed[] = $path;
            return sha1($localContents[$path]);
        });
        $diff['hashed'] = $hashed;

        return $diff;
    }

    private static function paths(array $entries): array
    {
        return array_column($entries, 'path');
    }

    public function testDetectsNewChangedUnchanged(): void
    {
        $diff = $this->compute(new Options());

        $this->assertSame(['content/a/new.jpg', 'content/a/huge.mp4'], self::paths($diff['new']));
        $this->assertSame(['content/a/changed.de.txt', 'content/a/grown.de.txt'], self::paths($diff['changed']));
        $this->assertSame([], $diff['deleted'], 'nothing is deleted without --delete');
        $this->assertSame(1, $diff['unchanged']);

        // Files whose size already differs are never hashed
        $this->assertSame(['content/a/default.de.txt', 'content/a/changed.de.txt'], $diff['hashed']);
    }

    public function testDelete(): void
    {
        $this->assertSame(['content/a/gone.jpg'], self::paths($this->compute(new Options(delete: true))['deleted']));
    }

    public function testMissingOnly(): void
    {
        $diff = $this->compute(new Options(missingOnly: true));

        $this->assertSame(['content/a/new.jpg', 'content/a/huge.mp4'], self::paths($diff['new']));
        $this->assertSame([], $diff['changed']);
        $this->assertSame([], $diff['hashed']);
    }

    public function testMaxSizeSkipsMediaOnly(): void
    {
        $diff = $this->compute(new Options(maxSize: 3000));

        $this->assertSame(['content/a/new.jpg'], self::paths($diff['new']));
        $this->assertSame(['content/a/huge.mp4'], self::paths($diff['skipped']));
        $this->assertSame('size', $diff['skipped'][0]['reason']);

        // Text files are never skipped for size
        $diff = $this->compute(new Options(maxSize: 1));
        $this->assertSame(['content/a/changed.de.txt', 'content/a/grown.de.txt'], self::paths($diff['changed']));
    }

    public function testSkippedFilesAreNotDeleted(): void
    {
        $diff = $this->compute(new Options(maxSize: 3000, delete: true));

        $this->assertSame(['content/a/gone.jpg'], self::paths($diff['deleted']));
    }

    public function testSummary(): void
    {
        $summary = Diff::summary($this->compute(new Options(delete: true)));

        $this->assertSame(2, $summary['new']);
        $this->assertSame(2, $summary['changed']);
        $this->assertSame(1, $summary['deleted']);
        $this->assertSame(1, $summary['unchanged']);
        $this->assertSame(2000 + 5000 + 4 + 10, $summary['bytes']);
    }
}
