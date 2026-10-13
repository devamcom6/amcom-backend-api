<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RequestKind;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\ServiceRequest;
use App\Services\Documents\DocumentService;
use App\Services\Leads\LeadService;
use App\Support\ApiResponse;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Contract §14.4 — private documents via short-lived signed URLs. */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    /** Owner or staff with a relevant view permission; 404 for anyone else. */
    public function download(Request $request, int $id): JsonResponse
    {
        $document = Document::query()->findOrFail($id);
        $user = $request->user();

        $allowed = $document->user_id === $user->id
            || ($user->isStaff() && ($user->hasPermission('investments.view') || $user->hasPermission('listings.view') || $user->hasPermission('users.view')));

        if (! $allowed) {
            throw ApiException::notFound();
        }

        if (! $document->hasFile()) {
            throw ApiException::conflict('This document is not available for download yet.', 'document_unavailable');
        }

        $link = $this->documents->temporaryDownload($document);

        return ApiResponse::success([
            'url' => $link['url'],
            'filename' => $link['filename'],
            'expires_at' => Format::iso($link['expires_at']),
        ]);
    }

    /** Signed route: GET /documents/{document}/file */
    public function file(Document $document): StreamedResponse
    {
        abort_unless($document->hasFile(), 404);

        return $this->documents->stream($document, inline: false);
    }

    /** Signed route: GET /documents/{document}/preview (agreement viewer) */
    public function preview(Document $document): StreamedResponse
    {
        abort_unless($document->hasFile(), 404);

        return $this->documents->stream($document, inline: true);
    }

    /** Signed route: GET /inspections/{serviceRequest}/calendar.ics */
    public function calendar(ServiceRequest $serviceRequest, LeadService $leads): Response
    {
        abort_unless($serviceRequest->kind === RequestKind::Inspection && $serviceRequest->scheduled_for !== null, 404);

        return response($leads->calendarFor($serviceRequest), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="inspection-'.$serviceRequest->reference.'.ics"',
        ]);
    }
}
