<?php

namespace BB\ContentPull;

use InvalidArgumentException;

/**
 * Everything that shapes a pull. CLI arguments and the query of a source
 * request both go through fromArray().
 */
final class Options
{
    /** "accounts" pulls only site/accounts, independent of the content */
    public const SCOPES = ['texts', 'media', 'all', 'accounts'];

    /**
     * @param array<int, string> $languages
     */
    public function __construct(
        public readonly string $scope = 'all',
        public readonly ?string $page = null,
        public readonly bool $recursive = false,
        public readonly array $languages = [],
        public readonly bool $missingOnly = false,
        public readonly ?int $maxSize = null,
        public readonly bool $delete = false,
        public readonly bool $dryRun = false,
        public readonly bool $accounts = false,
        public readonly bool $includeChanges = false,
        public readonly bool $archive = false,
    ) {
        if (in_array($scope, self::SCOPES, true) === false) {
            throw new InvalidArgumentException('Invalid scope "' . $scope . '" (expected texts, media, all or accounts)');
        }

        foreach ($languages as $code) {
            if (preg_match('/^[a-zA-Z0-9_-]{1,16}$/', $code) !== 1) {
                throw new InvalidArgumentException('Invalid language code "' . $code . '"');
            }
        }
    }

    /**
     * Keys as the CLI options ("missing-only"); flags may be booleans or
     * "1"/"true"/"yes".
     *
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $get = fn (string $key): mixed => $input[$key] ?? null;

        $page = $get('page');
        $page = is_string($page) === true && trim($page) !== '' ? Paths::normalizePageId($page) : null;

        return new self(
            scope: self::scope($get('scope')),
            page: $page,
            recursive: self::flag($get('recursive')),
            languages: self::list($get('lang')),
            missingOnly: self::flag($get('missing-only')),
            maxSize: Size::parse($get('max-size')),
            delete: self::flag($get('delete')),
            dryRun: self::flag($get('dry-run')),
            accounts: self::flag($get('accounts')),
            includeChanges: self::flag($get('include-changes')),
            archive: self::flag($get('archive')),
        );
    }

    /**
     * Whether site/accounts is part of this pull (--accounts or
     * --scope=accounts).
     */
    public function includesAccounts(): bool
    {
        return $this->accounts === true || $this->scope === 'accounts';
    }

    /**
     * The part of the options the source needs to pre-filter its manifest
     * or archive. Diff-only options (missing-only, delete …) stay local.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = ['scope' => $this->scope];

        if ($this->page !== null) {
            $query['page'] = $this->page === '' ? 'site' : $this->page;
        }

        if ($this->recursive === true) {
            $query['recursive'] = '1';
        }

        if ($this->languages !== []) {
            $query['lang'] = implode(',', $this->languages);
        }

        if ($this->accounts === true) {
            $query['accounts'] = '1';
        }

        if ($this->includeChanges === true) {
            $query['include-changes'] = '1';
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope'          => $this->scope,
            'page'           => $this->page,
            'recursive'      => $this->recursive,
            'languages'      => $this->languages,
            'missingOnly'    => $this->missingOnly,
            'maxSize'        => $this->maxSize,
            'delete'         => $this->delete,
            'dryRun'         => $this->dryRun,
            'accounts'       => $this->accounts,
            'includeChanges' => $this->includeChanges,
            'archive'        => $this->archive,
        ];
    }

    /**
     * One-line description for logs and CLI output.
     */
    public function describe(): string
    {
        $parts = ['scope=' . $this->scope];

        if ($this->page !== null) {
            $parts[] = 'page=' . ($this->page === '' ? 'site' : $this->page) . ($this->recursive ? ' (recursive)' : '');
        }

        if ($this->languages !== []) {
            $parts[] = 'lang=' . implode(',', $this->languages);
        }

        if ($this->maxSize !== null) {
            $parts[] = 'max-size=' . Size::format($this->maxSize);
        }

        foreach (['missingOnly' => 'missing-only', 'delete' => 'delete', 'dryRun' => 'dry-run', 'accounts' => 'accounts', 'includeChanges' => 'include-changes', 'archive' => 'archive'] as $property => $label) {
            if ($this->$property === true) {
                $parts[] = $label;
            }
        }

        return implode(' ', $parts);
    }

    private static function scope(mixed $value): string
    {
        return is_string($value) === true && $value !== '' ? strtolower($value) : 'all';
    }

    private static function flag(mixed $value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<int, string>
     */
    private static function list(mixed $value): array
    {
        if (is_string($value) === true) {
            $value = explode(',', $value);
        }

        if (is_array($value) === false) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $value
        ), fn ($item) => $item !== '')));
    }
}
