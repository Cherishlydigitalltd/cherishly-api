<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(
        string $message = 'Success',
        mixed $data = null,
        int $statusCode = 200
    ): JsonResponse {
        return response()->json([
            'status'  => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $statusCode);
    }

    public static function error(
        string $message = 'An error occurred',
        mixed $errors = null,
        int $statusCode = 400
    ): JsonResponse {
        return response()->json([
            'status'  => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors,
        ], $statusCode);
    }

    public static function validationError(
        mixed $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return self::error($message, $errors, 422);
    }

    public static function unauthorized(
        string $message = 'Unauthorized'
    ): JsonResponse {
        return self::error($message, null, 401);
    }

    public static function forbidden(
        string $message = 'Forbidden'
    ): JsonResponse {
        return self::error($message, null, 403);
    }

    public static function notFound(
        string $message = 'Resource not found'
    ): JsonResponse {
        return self::error($message, null, 404);
    }

    public static function serverError(
        string $message = 'Internal server error'
    ): JsonResponse {
        return self::error($message, null, 500);
    }
}
