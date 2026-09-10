<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Registrar un nuevo cliente.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'customer',
        ]);

        $token = auth('api')->login($user);

        return $this->respondWithToken(
            $token,
            'Usuario registrado correctamente',
            201
        );
    }

    /**
     * Iniciar sesión y generar un token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! $token = auth('api')->attempt($credentials)) {
            return response()->json([
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        return $this->respondWithToken(
            $token,
            'Inicio de sesión correcto'
        );
    }

    /**
     * Obtener los datos del usuario autenticado.
     */
    public function me(): JsonResponse
    {
        return response()->json([
            'user' => auth('api')->user(),
        ]);
    }

    /**
     * Cerrar sesión e invalidar el token.
     */
    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ]);
    }

    /**
     * Renovar el token JWT.
     */
    public function refresh(): JsonResponse
    {
        return $this->respondWithToken(
            auth('api')->refresh(),
            'Token renovado correctamente'
        );
    }

    /**
     * Construir la respuesta que contiene el token.
     */
    private function respondWithToken(
        string $token,
        string $message,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ], $status);
    }
}
