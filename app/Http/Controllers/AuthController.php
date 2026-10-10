<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function login(Request $request): JsonResponse
    {
        $username = strtolower(trim((string) $request->input('username', '')));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Usuario o contraseña inválidos.',
                [],
                401
            );
        }

        $user = User::where('username', $username)->first();

        if (!$user || !$user->verifyPassword($password)) {
            // CA-01.2: no revela si falló el usuario o la clave
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Usuario o contraseña inválidos.',
                [],
                401
            );
        }

        $tokenData = $this->jwtService->generateToken($user);

        return ApiResponse::success($tokenData, 200);
    }

    public function health(): JsonResponse
    {
        return ApiResponse::success(['status' => 'ok'], 200);
    }
}
