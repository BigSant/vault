<?php
namespace App\Http;
use Closure;
use Illuminate\Http\Request;
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        abort_if((int)$request->header('Content-Length',0)>1048576,413);
        $response=$next($request);
        foreach (['Cache-Control'=>'private, no-store','Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff',
            'Referrer-Policy'=>'no-referrer','X-Frame-Options'=>'DENY',
            'Content-Security-Policy'=>"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'"] as $key=>$value) $response->headers->set($key,$value);
        return $response;
    }
}
