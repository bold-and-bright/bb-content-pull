<?php

namespace BB\ContentPull;

use Kirby\Http\Remote;
use RuntimeException;

/**
 * HTTP client for the source endpoints, built on Kirby's Remote class.
 */
final class Client
{
    public function __construct(
        private readonly string $source,
        private readonly string $token,
        private readonly int $timeout = 120,
        private readonly mixed $ca = Remote::CA_INTERNAL,
    ) {
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return array{files: array<int, array>, languages?: array<int, string>}
     */
    public function manifest(Options $options, bool $hash = true): array
    {
        $query = $options->toQuery();

        if ($hash === false) {
            $query['hash'] = '0';
        }

        $data = json_decode($this->get('manifest', $query), true);

        if (is_array($data) === false || is_array($data['files'] ?? null) === false) {
            throw new RuntimeException('The source returned an invalid manifest');
        }

        return $data;
    }

    public function download(string $path): string
    {
        return $this->get('file', ['path' => $path]);
    }

    /**
     * Downloads the filtered set as ZIP into the given file.
     */
    public function archive(Options $options, string $target): void
    {
        $query = $options->toQuery();

        if ($options->maxSize !== null) {
            $query['max-size'] = (string) $options->maxSize;
        }

        if (file_put_contents($target, $this->get('archive', $query)) === false) {
            throw new RuntimeException('Could not write the archive to ' . $target);
        }
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $endpoint, array $query): string
    {
        $url = $this->source . '/bb-content-pull/' . $endpoint . '?' . http_build_query($query);

        $remote = Remote::get($url, [
            'headers' => [
                'Authorization'        => 'Bearer ' . $this->token,
                // Fallback for hosts that strip the Authorization header
                'X-Content-Pull-Token' => $this->token,
                'Accept'               => $endpoint === 'manifest' ? 'application/json' : '*/*',
            ],
            'timeout' => $this->timeout,
            'ca'      => $this->ca,
        ]);

        $code = $remote->code();

        if ($code !== 200) {
            $message = json_decode((string) $remote->content(), true)['error'] ?? null;

            throw new RuntimeException(match ($code) {
                401     => 'The source rejected the token (401)',
                404     => $endpoint === 'file'
                    ? 'File not found on the source (404)'
                    : 'The source does not serve content (404) — is bb.contentpull.serve enabled there?',
                default => 'The source answered with HTTP ' . $code . ($message ? ': ' . $message : ''),
            });
        }

        return (string) $remote->content();
    }
}
