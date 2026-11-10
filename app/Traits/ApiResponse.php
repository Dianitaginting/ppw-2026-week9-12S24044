<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function successResponse(
        mixed $data,
        string $message,
        int $status = 200,
        ?array $meta = null,
    ): JsonResponse {
        $body = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    protected function createdResponse(
        mixed $data,
        string $locationUrl,
        string $message,
    ): JsonResponse {
        return $this->successResponse($data, $message, 201)
            ->header('Location', $locationUrl);
    }

    protected function noContentResponse(): JsonResponse
    {
        return response()->json(null, 204);
    }

    protected function problemResponse(
        string $title,
        string $detail,
        int $status,
        string $errorSlug,
        ?array $errors = null,
    ): JsonResponse {
        $problem = [
            'type' => url("/docs/errors/{$errorSlug}"),
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => request()->getRequestUri(),
        ];

        if ($errors !== null) {
            $problem['errors'] = $errors;
        }

        return response()->json($problem, $status)
            ->header('Content-Type', 'application/problem+json');
    }
}
