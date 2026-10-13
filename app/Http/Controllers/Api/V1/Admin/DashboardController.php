<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /admin/dashboard (contract §15.1) — dashboard.view */
class DashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboardService $dashboard): JsonResponse
    {
        [$from, $to] = self::range($request);

        return ApiResponse::success($dashboard->build($from, $to));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function range(Request $request, int $defaultDays = 30): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to')) : CarbonImmutable::today();
        $from = $request->query('from') ? CarbonImmutable::parse($request->query('from')) : $to->subDays($defaultDays - 1);

        // Bound the range to keep queries cheap.
        if ($from->diffInDays($to) > 731) {
            $from = $to->subDays(731);
        }

        return [$from, $to];
    }
}
