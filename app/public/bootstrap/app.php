<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $m) {
        $m->append(App\Http\SecurityHeaders::class);
        $m->alias(['vault.auth'=>App\Http\OwnerSession::class,'vault.machine'=>App\Http\MachineAuth::class]);
        $m->trimStrings(except: ['password','password_confirmation','current_password','secret','secret_fields.*','import','code','bootstrap_code']);
        $m->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $e) {
        // Never log request values, exception messages, SQL bindings or stack arguments.
        $e->report(function (Throwable $error) { error_log('vault_request_failed:'.get_class($error)); return false; });
        $e->dontFlash(['password','password_confirmation','current_password','secret','secret_fields','import','code','bootstrap_code','token']);
        $e->render(function (Throwable $error, Request $r) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ValidationException ? 422 : 503);
            $status = in_array($status,[400,401,403,404,405,409,413,415,419,422,429,503]) ? $status : 503;
            $codes=[400=>'invalid_request',401=>'authentication_required',403=>'access_denied',404=>'not_found',405=>'method_not_allowed',409=>'conflict',413=>'too_large',415=>'unsupported_media_type',419=>'session_expired',422=>'invalid_input',429=>'rate_limited',503=>'unavailable'];
            if ($r->is('api/*') || $r->expectsJson()) return response()->json(['error'=>$codes[$status]],$status);
            return response()->view('error',['status'=>$status],$status);
        });
    })->create();
