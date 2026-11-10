<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        URL::forceScheme('https');
        RateLimiter::for('login', fn ($r) => Limit::perMinute(6)->by($r->ip()));
        RateLimiter::for('mfa', fn ($r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('sensitive', fn ($r) => Limit::perMinute(10)->by((string) ($r->user()?->id ?? $r->ip())));
        RateLimiter::for('api-ip', fn ($r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('machine', fn ($r) => Limit::perMinute(60)->by(hash('sha256', (string) $r->bearerToken())));
    }
}
