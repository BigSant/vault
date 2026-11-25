<?php

use App\Http\Account;
use App\Http\MachineAuth;
use App\Http\OwnerSession;
use App\Http\SecurityHeaders;
use App\Vault\ConfirmationRequired;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', then: function () {
        // Unknown pages run through the web middleware, so the "Not found" page knows who is signed in. API paths stay out.
        Route::fallback(fn () => abort(404))->middleware('web')->where('fallbackPlaceholder', '^(?!api(/|$)).*');
    })
    ->withMiddleware(function (Middleware $m) {
        $m->append(SecurityHeaders::class);
        $m->alias(['vault.auth' => OwnerSession::class, 'vault.machine' => MachineAuth::class]);
        $m->trimStrings(except: ['password', 'password_confirmation', 'current_password', 'confirm_password', 'secret', 'secret_fields.*', 'import', 'code', 'bootstrap_code']);
        $m->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $e) {
        // Never log request values, exception messages, SQL bindings or stack arguments.
        $e->report(function (Throwable $error) {
            error_log('vault_request_failed:'.get_class($error));

            return false;
        });
        // Every form field that carries a secret. None of them is ever put into the session.
        $secretInputs = ['password', 'password_confirmation', 'current_password', 'confirm_password', 'secret', 'secret_fields', 'import', 'code', 'bootstrap_code', 'token'];
        $e->dontFlash($secretInputs);
        $e->render(function (Throwable $error, Request $r) use ($secretInputs) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ValidationException ? 422 : 503);
            $status = in_array($status, [400, 401, 403, 404, 405, 409, 413, 415, 419, 422, 429, 503]) ? $status : 503;
            $codes = [400 => 'invalid_request', 401 => 'authentication_required', 403 => 'access_denied', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'conflict', 413 => 'too_large', 415 => 'unsupported_media_type', 419 => 'session_expired', 422 => 'invalid_input', 429 => 'rate_limited', 503 => 'unavailable'];
            if ($r->is('api/*') || $r->expectsJson()) {
                return response()->json(['error' => $codes[$status]], $status);
            }

            if ($error instanceof ConfirmationRequired) {
                return response()->view('confirm', ['return' => Account::returnPath($r), 'blocked' => ! $error->failed, 'failed' => $error->failed], 403);
            }

            // A form that fails validation is shown again with its messages and its non-secret input, instead of an error page.
            // Only for a signed-in person posting a web form; the way back is a path on this site (Account::returnPath).
            if ($error instanceof ValidationException && ! $r->isMethodSafe() && $r->hasSession()) {
                try {
                    if (Auth::check() && $r->session()->get('mfa_at')) {
                        // Dropped: the secret fields by name, anything that looks like one, the CSRF token and oversized values.
                        $old = array_filter($r->except($secretInputs), fn ($value, $key) => ! preg_match('/secret|password|token|code|import/i', (string) $key)
                            && strlen((string) json_encode($value)) <= 4000, ARRAY_FILTER_USE_BOTH);

                        // Sessions are stored as JSON, which the usual ViewErrorBag ($errors) does not survive: the layout reads
                        // the plain form_errors array instead.
                        return redirect(Account::returnPath($r), 303)->withErrors($error->errors())->withInput($old)->with(['form_errors' => array_slice($error->errors(), 0, 8, true), 'error_form' => $r->getPathInfo()]);
                    }
                } catch (Throwable) {
                    // Without the session or the database the error page below still works.
                }
            }

            // Validation texts name fields and rules, never submitted values.
            $messages = $error instanceof ValidationException ? array_slice(array_merge(...array_values($error->errors()) ?: [[]]), 0, 5) : [];

            // Signed-in people keep the header on an error page. Guests, half-finished sign-ins and a failing service get the bare page.
            if (! in_array($status, [401, 419, 503], true)) {
                try {
                    if (Auth::check() && $r->hasSession() && $r->session()->get('mfa_at')) {
                        return response()->view('error', ['status' => $status, 'messages' => $messages, 'shell' => true], $status);
                    }
                } catch (Throwable) {
                    // The header needs the database; fall through to the page that needs nothing.
                }
            }

            return response()->view('error', ['status' => $status, 'messages' => $messages], $status);
        });
    })->create();
