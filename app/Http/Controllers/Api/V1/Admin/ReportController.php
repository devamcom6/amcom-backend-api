<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ReportType;
use App\Http\Controllers\Controller;
use App\Services\Admin\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Contract §15.10 — reports.view / reports.export */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function show(Request $request, string $type): JsonResponse
    {
        return ApiResponse::success($this->build($request, $type));
    }

    /** CSV body with Content-Disposition (not the JSON envelope). */
    public function export(Request $request, string $type): StreamedResponse
    {
        $request->validate(['format' => ['nullable', 'in:csv']]);

        return $this->reports->exportCsv($this->build($request, $type));
    }

    private function build(Request $request, string $type): array
    {
        $reportType = ReportType::tryFrom($type) ?? abort(404);
        $request->validate(['group_by' => ['nullable', 'in:day,week,month']]);
        [$from, $to] = DashboardController::range($request, 180);

        return $this->reports->build($reportType, $from, $to, $request->query('group_by', 'month'));
    }
}
