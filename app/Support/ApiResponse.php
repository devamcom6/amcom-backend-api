<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Builds the response envelope shared by every endpoint (contract §1.4).
 */
final class ApiResponse
{
    /** Keep ₦ and slashes readable; never escape-encode for HTML (JSON API only). */
    public const JSON_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public const DEFAULT_MESSAGE = 'Request completed successfully.';

    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        return new JsonResponse([
            'success' => true,
            'message' => $message ?? self::DEFAULT_MESSAGE,
            'data' => $data,
        ], $status, [], self::JSON_OPTIONS);
    }

    public static function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return self::success($data, $message ?? 'Created successfully.', 201);
    }

    /**
     * @param  class-string<JsonResource>|callable|null  $transform  resource class or item mapper
     */
    public static function paginated(LengthAwarePaginator $paginator, string|callable|null $transform = null, ?string $message = null): JsonResponse
    {
        $items = collect($paginator->items());

        if (is_string($transform)) {
            $data = $transform::collection($items)->resolve(request());
        } elseif (is_callable($transform)) {
            $data = $items->map($transform)->values()->all();
        } else {
            $data = $items->values()->all();
        }

        return new JsonResponse([
            'success' => true,
            'message' => $message ?? self::DEFAULT_MESSAGE,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 200, [], self::JSON_OPTIONS);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(string $message, string $code, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        $body = ['success' => false, 'message' => $message, 'code' => $code];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status, $headers, self::JSON_OPTIONS);
    }
}
