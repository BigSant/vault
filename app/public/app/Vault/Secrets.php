<?php

namespace App\Vault;

use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** OpenBao owns encryption and version storage; callers never handle master keys. */
final class Secrets
{
    private ?string $token = null;

    private function call(string $method, string $path, array $data = [], bool $missingOk = false): ?array
    {
        try {
            $client = Http::baseUrl(config('vault.bao_url').'/v1/')->timeout(5)->connectTimeout(2)->withoutRedirecting();
            if ($this->token === null) {
                $credentials = json_decode(file_get_contents(config('vault.credentials')), true, flags: JSON_THROW_ON_ERROR);
                $login = $client->post('auth/approle/login', $credentials);
                if (! $login->successful()) {
                    abort(503);
                }
                $this->token = $login->json('auth.client_token');
            }
            $response = $client->withHeaders(['X-Vault-Token' => $this->token])->send($method, $path, ['json' => $data]);
            if ($response->status() === 400 && $method === 'POST') {
                abort(409);
            }
            if ($missingOk && $response->status() === 404) {
                return null;
            }
            if (! $response->successful()) {
                abort(503);
            }

            return $response->json() ?? [];
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            abort(503);
        }
    }

    private function path(string $path): string
    {
        abort_unless(preg_match('/^(entries|auth|links|settings)\/[0-9a-f-]{36}$/D', $path), 422);

        return $path;
    }

    public function write(string $path, #[\SensitiveParameter] array $data, int $cas): int
    {
        $result = $this->call('POST', 'secret/data/'.$this->path($path), ['options' => ['cas' => $cas], 'data' => $data]);

        return (int) $result['data']['version'];
    }

    public function read(string $path, ?int $version = null): array
    {
        return $this->call('GET', 'secret/data/'.$this->path($path).($version ? '?version='.$version : ''))['data']['data'];
    }

    /** Version metadata (created/deleted/destroyed), no values. Null when the backend cannot say. */
    public function versions(string $path): ?array
    {
        try {
            $versions = $this->call('GET', 'secret/metadata/'.$this->path($path))['data']['versions'] ?? null;
        } catch (HttpException) {
            return null;
        }

        return is_array($versions) ? array_combine(array_map('intval', array_keys($versions)), array_values($versions)) : null;
    }

    /** The latest version's data, or null when nothing is stored at the path (never written, or destroyed). */
    public function find(string $path): ?array
    {
        $result = $this->call('GET', 'secret/data/'.$this->path($path), missingOk: true);

        return $result === null ? null : ($result['data']['data'] ?? null);
    }

    /** Writes a new version over whatever is there, still with CAS: a concurrent writer makes this fail (409). */
    public function put(string $path, #[\SensitiveParameter] array $data): void
    {
        $meta = $this->call('GET', 'secret/metadata/'.$this->path($path), missingOk: true);
        $this->write($path, $data, (int) ($meta['data']['current_version'] ?? 0));
    }

    public function resetMfa(string $userId, #[\SensitiveParameter] string $seed): void
    {
        $path = $this->path('auth/'.$userId);
        $current = $this->call('GET', 'secret/data/'.$path);
        $this->write($path, ['totp' => $seed], (int) $current['data']['metadata']['version']);
    }

    public function destroy(string $path): void
    {
        $this->call('DELETE', 'secret/metadata/'.$this->path($path));
    }
}
