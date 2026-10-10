<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $role = $request->input('role');
        if ($role === 'admin') {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'No está permitido otorgar el rol de administrador vía API.',
                [['field' => 'role', 'issue' => 'Rol admin no permitido']],
                422
            );
        }

        if ($role !== 'librarian') {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El rol debe ser estrictamente librarian.',
                [['field' => 'role', 'issue' => 'Rol inválido']],
                422
            );
        }

        $username = strtolower(trim((string) $request->input('username', '')));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El nombre de usuario y la contraseña son obligatorios.',
                [],
                422
            );
        }

        if (User::where('username', $username)->exists()) {
            return ApiResponse::error(
                'USERNAME_TAKEN',
                'El nombre de usuario ya está registrado en el sistema.',
                [['field' => 'username', 'issue' => 'Nombre de usuario duplicado']],
                409
            );
        }

        $user = new User();
        $user->username = $username;
        $user->password = $password;
        $user->role = 'librarian';
        $user->save();

        return ApiResponse::success([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
        ], 201);
    }
}
