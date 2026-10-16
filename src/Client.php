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
        private string $token,
        private readonly int $timeout = 120,
        private readonly mixed $ca = Remote::CA_INTERNAL,
    ) {
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * Logs in with Kirby credentials; all following requests use the
     * session token the source returns.
     *
     * @return array{token: string, expires: int, user: string, role: string}
     */
    public function login(string $email, #[\SensitiveParameter] string $password): array
    {
        $credentials = base64_encode($email . ':' . $password);
        $data        = json_decode($this->request('POST', 'login', [], [
            'Authorization'       => 'Basic ' . $credentials,
            // Fallback for hosts that strip the Authorization header
            'X-Content-Pull-Auth' => $credentials,
        ]), true);

        if (is_array($data) === false || is_string($data['token'] ?? null) === false) {
            throw new RuntimeException('The source returned no session token');
        }

        $this->token = $data['token'];

        return $data;
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
        return $this->request('GET', $endpoint, $query, [
            'Authorization'        => 'Bearer ' . $this->token,
            // Fallback for hosts that strip the Authorization header
            'X-Content-Pull-Token' => $this->token,
        ]);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function request(string $method, string $endpoint, array $query, array $headers): string
    {
        $url = $this->source . '/bb-content-pull/' . $endpoint . ($query !== [] ? '?' . http_build_query($query) : '');

        $remote = Remote::request($url, [
            'method'  => $method,
            'headers' => $headers + [
                'Accept' => in_array($endpoint, ['manifest', 'login'], true) ? 'application/json' : '*/*',
            ],
            'data'    => $method === 'POST' ? '' : [],
            'timeout' => $this->timeout,
            'ca'      => $this->ca,
        ]);

        $code    = $remote->code();
        $content = (string) $remote->content();

        // Remote's header callback references the Remote object, which holds
        // the curl handle: a reference cycle that only the cycle collector
        // frees. Hundreds of downloads would otherwise pile up open sockets
        // until "Too many open files".
        unset($remote);
        gc_collect_cycles();

        if ($code !== 200) {
            $message = json_decode($content, true)['error'] ?? null;

            throw new RuntimeException(match ($code) {
                401     => $endpoint === 'login'
                    ? 'Login failed: wrong email or password, or too many attempts (401)'
                    : 'The source rejected the token (401)',
                403     => 'The source refused (403)' . ($message ? ': ' . $message : ''),
                404     => $endpoint === 'file'
                    ? 'File not found on the source (404)'
                    : 'The source does not serve content (404) — is bb.contentpull.serve enabled there?',
                default => 'The source answered with HTTP ' . $code . ($message ? ': ' . $message : ''),
            });
        }

        return $content;
    }
}
