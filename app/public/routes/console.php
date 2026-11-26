<?php

use App\Vault\AccountRecovery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('vault:status', function () {
    $this->info('Database: '.(DB::select('SELECT 1') ? 'ready' : 'unavailable'));
    $this->info('Owners: '.DB::table('members')->where('role', 'owner')->count());
});

Artisan::command('vault:recover-user {email}', function () {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    app(AccountRecovery::class)->reset($this->argument('email'), $input['password'] ?? '');
    $this->info('User recovered; password replaced, MFA enrollment required, sessions revoked.');
});
// Every few minutes (ops/vault-links.timer): one-time links whose lifetime is over end, open ones are checked, so a
// read is on record even when nobody opens Links.
Artisan::command('vault:links', function () {
    $this->info('Checked '.app(\App\Vault\Links::class)->sweep().' open one-time links.');
});
Artisan::command('vault:maintenance', function () {
    DB::table('operations')->whereIn('state', ['pending', 'approved', 'running', 'requires_user_action'])->where('expires_at', '<=', now())->update(['state' => 'expired', 'updated_at' => now()]);
    DB::table('sessions')->where('last_activity', '<', time() - 8 * 3600)->delete();
    DB::table('cache')->where('expiration', '<', time())->delete();
    DB::table('cache_locks')->where('expiration', '<', time())->delete();
    app(\App\Vault\Links::class)->maintenance();
    $this->info('Expired operation, link, session and cache maintenance completed.');
});
