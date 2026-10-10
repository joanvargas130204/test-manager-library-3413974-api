<?php

use App\Http\Middleware\JwtAuthenticate;
use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt.auth' => JwtAuthenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                $details = [];
                foreach ($e->errors() as $field => $messages) {
                    $details[] = [
                        'field' => $field,
                        'issue' => $messages[0] ?? 'Inválido',
                    ];
                }
                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    'Uno o más campos enviados son inválidos.',
                    $details,
                    422
                );
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'NOT_FOUND',
                    'El recurso solicitado no fue encontrado.',
                    [],
                    404
                );
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'FORBIDDEN_ROLE',
                    'No tiene permisos para realizar esta acción.',
                    [],
                    403
                );
            }
        });
    })->create();
