<?php
namespace App\Http;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class MachineAuth
{
    public function handle(Request $r,Closure $next){
        abort_if($r->headers->has('Origin'),403);
        $token=$r->bearerToken();abort_unless(is_string($token)&&strlen($token)===64,401);
        $m=DB::table('machines')->where('token_hash',hash('sha256',$token))->whereNull('revoked_at')->where('expires_at','>',now())->first();
        abort_unless($m,401);$r->attributes->set('machine',$m);return $next($r);
    }
}
