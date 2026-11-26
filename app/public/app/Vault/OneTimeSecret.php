<?php

namespace App\Vault;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The Onetime Secret API v2 (https://REGION.onetimesecret.com/api/v2), as the workspace's account (HTTP Basic: the
 * account email and its API token). Nothing here logs or returns a request body; errors carry the HTTP status only.
 */
final class OneTimeSecret
{
    /** Regions are separate deployments: a link can only be read in the region that made it. */
    public const REGIONS = ['eu' => 'Europe (eu)', 'uk' => 'United Kingdom (uk)', 'us' => 'United States (us)', 'ca' => 'Canada (ca)', 'nz' => 'Aotearoa New Zealand (nz)'];

    /** The lifetimes Vault offers, in seconds. The account's plan may allow fewer: Onetime Secret then refuses. */
    public const LIFETIMES = [3600 => '1 hour', 86400 => '1 day', 259200 => '3 days', 604800 => '7 days', 1209600 => '14 days', 2592000 => '30 days'];

    /** Receipt states, mapped to the words Vault uses for a link. */
    private const STATES = ['new' => 'active', 'shared' => 'active', 'previewed' => 'previewed', 'revealed' => 'revealed',
        'burned' => 'burned', 'expired' => 'expired', 'orphaned' => 'expired', 'received' => 'revealed', 'viewed' => 'previewed'];

    public static function origin(string $region): string
    {
        abort_unless(isset(self::REGIONS[$region]), 422);

        return sprintf((string) config('vault.onetimesecret_origin', 'https://%s.onetimesecret.com'), $region);
    }

    private function client(object $settings, #[\SensitiveParameter] string $token): PendingRequest
    {
        return Http::baseUrl(self::origin($settings->region).'/api/v2/')->withBasicAuth($settings->email, $token)
            ->acceptJson()->asJson()->timeout(10)->connectTimeout(3)->withoutRedirecting();
    }

    /** @return array{secret: string, receipt: string, url: string, ttl: int} ttl: the lifetime the service applied, which a plan may shorten. */
    public function conceal(object $settings, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $text, int $lifetime, #[\SensitiveParameter] ?string $passphrase): array
    {
        $secret = ['secret' => $text, 'ttl' => $lifetime];
        if ($passphrase !== null && $passphrase !== '') {
            $secret['passphrase'] = $passphrase;
        }
        $record = $this->send(fn () => $this->client($settings, $token)->post('secret/conceal', ['secret' => $secret]))['record'] ?? [];
        $id = $record['secret']['identifier'] ?? $record['secret']['key'] ?? null;
        $receipt = $record['receipt']['identifier'] ?? $record['receipt']['key'] ?? null;
        if (! is_string($id) || ! is_string($receipt) || ! preg_match('/^[A-Za-z0-9]{8,128}$/D', $id) || ! preg_match('/^[A-Za-z0-9]{8,128}$/D', $receipt)) {
            throw new OneTimeSecretError(502);
        }

        $applied = $record['secret']['secret_ttl'] ?? $record['receipt']['secret_ttl'] ?? null;
        $ttl = is_numeric($applied) && (int) $applied > 0 ? min((int) $applied, $lifetime) : $lifetime;

        return ['secret' => $id, 'receipt' => $receipt, 'url' => self::origin($settings->region).'/secret/'.$id, 'ttl' => $ttl];
    }

    /** The link's state from its receipt: active, previewed, revealed, burned or expired. */
    public function state(object $settings, #[\SensitiveParameter] string $token, string $receipt): string
    {
        try {
            $json = $this->send(fn () => $this->client($settings, $token)->get('receipt/'.rawurlencode($receipt)));
        } catch (OneTimeSecretError $e) {
            if ($e->status === 404) {
                return 'expired';
            }
            throw $e;
        }
        $record = $json['record'] ?? [];
        $state = self::STATES[$record['state'] ?? ''] ?? throw new OneTimeSecretError(502);
        // The service counts a visit that did not reveal the content apart from the state.
        $visited = (int) ($json['details']['view_count'] ?? 0) > 0 || ! empty($record['first_access']) || in_array($record['is_previewed'] ?? null, [true, 'true', '1', 1], true);

        return $state === 'active' && $visited ? 'previewed' : $state;
    }

    /**
     * Burns the secret and returns the state the service reports afterwards: "burned", or what it already was
     * ("revealed" when the recipient read it first). Throws OneTimeSecretError when the answer says neither.
     */
    public function burn(object $settings, #[\SensitiveParameter] string $token, string $receipt, #[\SensitiveParameter] ?string $passphrase): string
    {
        $body = ['continue' => true];
        if ($passphrase !== null && $passphrase !== '') {
            $body['passphrase'] = $passphrase;
        }
        $record = $this->send(fn () => $this->client($settings, $token)->post('receipt/'.rawurlencode($receipt).'/burn', $body))['record'] ?? [];
        $state = self::STATES[$record['state'] ?? $record['receipt']['state'] ?? ''] ?? null;

        return in_array($state, ['burned', 'revealed', 'expired'], true) ? $state : throw new OneTimeSecretError(502);
    }

    /** Signs in with the account: true when the email and token are accepted. */
    public function check(object $settings, #[\SensitiveParameter] string $token): void
    {
        $this->send(fn () => $this->client($settings, $token)->get('receipt/recent'));
    }

    private function send(\Closure $call): array
    {
        try {
            $response = $call();
        } catch (\Throwable) {
            throw new OneTimeSecretError(0);
        }
        if (! $response->successful()) {
            $type = $response->json('error_type');
            throw new OneTimeSecretError($response->status(), is_string($type) && preg_match('/^[A-Za-z]{1,60}$/D', $type) ? $type : null);
        }
        $json = $response->json();

        return is_array($json) ? $json : throw new OneTimeSecretError(502);
    }
}
