<?php

namespace BB\ContentPull\Tests;

use BB\ContentPull\Options;
use BB\ContentPull\Size;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function testFromCliArguments(): void
    {
        $options = Options::fromArray([
            'scope'        => 'texts',
            'page'         => '/about/team/',
            'recursive'    => true,
            'lang'         => 'de, en,,de',
            'missing-only' => true,
            'max-size'     => '20M',
            'dry-run'      => true,
            'delete'       => false,
        ]);

        $this->assertSame('texts', $options->scope);
        $this->assertSame('about/team', $options->page);
        $this->assertTrue($options->recursive);
        $this->assertSame(['de', 'en'], $options->languages);
        $this->assertTrue($options->missingOnly);
        $this->assertSame(20 * 1024 * 1024, $options->maxSize);
        $this->assertTrue($options->dryRun);
        $this->assertFalse($options->delete);
    }

    public function testEmptyValuesFallBackToDefaults(): void
    {
        $options = Options::fromArray(['scope' => '', 'page' => '', 'lang' => '', 'max-size' => '', 'dry-run' => 'false']);

        $this->assertSame('all', $options->scope);
        $this->assertNull($options->page);
        $this->assertSame([], $options->languages);
        $this->assertNull($options->maxSize);
        $this->assertFalse($options->dryRun);
    }

    public function testQueryRoundTrip(): void
    {
        $options = new Options(scope: 'media', page: '', recursive: true, languages: ['de'], missingOnly: true);
        $query   = $options->toQuery();

        $this->assertSame(['scope' => 'media', 'page' => 'site', 'recursive' => '1', 'lang' => 'de'], $query);
        $this->assertSame('', Options::fromArray($query)->page);
    }

    public function testRejectsInvalidScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Options::fromArray(['scope' => 'everything']);
    }

    public function testRejectsInvalidLanguage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Options::fromArray(['lang' => '../de']);
    }

    public function testSize(): void
    {
        $this->assertSame(500, Size::parse('500'));
        $this->assertSame(512 * 1024, Size::parse('512K'));
        $this->assertSame(20 * 1024 ** 2, Size::parse('20m'));
        $this->assertSame(20 * 1024 ** 2, Size::parse('20MB'));
        $this->assertSame((int) (1.5 * 1024 ** 3), Size::parse('1.5G'));
        $this->assertNull(Size::parse(''));
        $this->assertSame('20 MB', Size::format(20 * 1024 ** 2));

        $this->expectException(InvalidArgumentException::class);
        Size::parse('20 Mio');
    }
}
