<?php

namespace BB\ContentPull;

/**
 * Decides which manifest paths belong to a pull. Applied identically on the
 * source (to keep the manifest small) and on the target (to know which local
 * files are in scope, e.g. for --delete).
 *
 * Size limits and missing-only are not part of the filter: they depend on
 * the comparison and are handled by Diff, which can report them as skipped.
 */
final class Filter
{
    /**
     * @param array<int, string> $knownLanguages language codes of the site, used to
     *                                           detect the language of text files
     */
    public function __construct(
        private readonly Options $options,
        private readonly array $knownLanguages = [],
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function roots(): array
    {
        return $this->options->accounts === true
            ? [Paths::CONTENT, Paths::ACCOUNTS]
            : [Paths::CONTENT];
    }

    public function accepts(string $path): bool
    {
        if (Paths::normalize($path, $this->roots()) === null) {
            return false;
        }

        if (Paths::isExcluded($path, $this->options->includeChanges) === true) {
            return false;
        }

        // Accounts are all-or-nothing: scope, page and language filters only
        // make sense for content.
        if (Paths::root($path) === Paths::ACCOUNTS) {
            return true;
        }

        return
            $this->acceptsScope($path) &&
            $this->acceptsPage($path) &&
            $this->acceptsLanguage($path);
    }

    private function acceptsScope(string $path): bool
    {
        return match ($this->options->scope) {
            'texts' => Paths::isText($path),
            'media' => Paths::isText($path) === false,
            default => true,
        };
    }

    private function acceptsPage(string $path): bool
    {
        $page = $this->options->page;

        if ($page === null) {
            return true;
        }

        $id = Paths::pageId($path);

        if ($id === $page) {
            return true;
        }

        if ($this->options->recursive === false) {
            return false;
        }

        // The site is the parent of everything
        return $page === '' || str_starts_with($id, $page . '/');
    }

    private function acceptsLanguage(string $path): bool
    {
        if ($this->options->languages === [] || Paths::isText($path) === false) {
            return true;
        }

        $language = Paths::language($path, $this->knownLanguages);

        // Files without a language code (single-language leftovers) are kept
        return $language === null || in_array($language, $this->options->languages, true);
    }
}
