<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void {
        URL::forceScheme('https');
        \Illuminate\Support\Facades\RateLimiter::for('machine',fn($r)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by(hash('sha256',(string)$r->bearerToken())));
    }
}
