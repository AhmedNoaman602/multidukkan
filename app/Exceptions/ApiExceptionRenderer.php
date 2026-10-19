<?php

namespace App\Exceptions;

use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

// API errors in the user's language, without class names or internals. Returning null hands the
// exception back to Laravel: validation errors, messages the app passed to abort(), and full
// details while app.debug is on. Reporting to the log is unaffected.
class ApiExceptionRenderer
{
    private const DEFAULT_AUTHORIZATION_MESSAGE = 'This action is unauthorized.';

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return null;
        }

        // An unknown route or a guest request fails before SetLocale runs.
        App::setLocale(SetLocale::localeFor($request));

        return match (true) {
            $e instanceof AuthenticationException => $this->json(401, __('messages.unauthenticated')),
            $e instanceof TooManyRequestsHttpException => $this->tooManyRequests($e, $request),
            $e instanceof NotFoundHttpException => $this->notFound($e, $request),
            $e instanceof MethodNotAllowedHttpException => $this->json(405, __('messages.route_not_found'), $e->getHeaders()),
            $e instanceof AccessDeniedHttpException && $e->getPrevious() instanceof AuthorizationException
                => $this->json(403, $this->authorizationMessage($e->getPrevious())),
            $e instanceof HttpExceptionInterface => null,
            (bool) config('app.debug') => null,
            default => $this->json(500, __('messages.server_error')),
        };
    }

    private function notFound(NotFoundHttpException $e, Request $request): ?JsonResponse
    {
        $missingRecord = $e->getPrevious() instanceof ModelNotFoundException
            || $e->getPrevious() instanceof RecordNotFoundException
            || $e->getPrevious() instanceof RecordsNotFoundException
            || $e->getPrevious() instanceof BackedEnumCaseNotFoundException;

        if ($missingRecord) {
            return $this->json(404, __('messages.resource_not_found'));
        }

        if ($request->route() === null) {
            return $this->json(404, __('messages.route_not_found'));
        }

        return null;
    }

    private function tooManyRequests(TooManyRequestsHttpException $e, Request $request): JsonResponse
    {
        $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

        $message = $request->is('api/login', 'api/register')
            ? trans_choice('auth.throttle', $seconds, ['seconds' => $seconds])
            : trans_choice('messages.too_many_requests', $seconds, ['seconds' => $seconds]);

        return $this->json(429, $message, $e->getHeaders());
    }

    private function authorizationMessage(AuthorizationException $e): string
    {
        $message = $e->getMessage();

        return $message === '' || $message === self::DEFAULT_AUTHORIZATION_MESSAGE
            ? __('messages.unauthorized')
            : $message;
    }

    private function json(int $status, string $message, array $headers = []): JsonResponse
    {
        return response()->json(['message' => $message], $status, $headers);
    }
}
