<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthenticate
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return ApiResponse::error('INVALID_CREDENTIALS', 'Token de autenticación requerido o inválido.', [], 401);
        }

        $token = $matches[1];
        $payload = $this->jwtService->validateToken($token);

        if (!$payload) {
            return ApiResponse::error('INVALID_CREDENTIALS', 'Token inválido o expirado.', [], 401);
        }

        $user = User::find($payload->sub);
        if (!$user) {
            return ApiResponse::error('INVALID_CREDENTIALS', 'Usuario no encontrado.', [], 401);
        }

        if (!empty($roles)) {
            if (!in_array($user->role, $roles, true)) {
                return ApiResponse::error('FORBIDDEN_ROLE', 'No tiene los permisos requeridos para esta operación.', [], 403);
            }
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_role', $user->role);

        return $next($request);
    }
}
