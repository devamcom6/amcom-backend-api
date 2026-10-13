<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private generated documents (agreements, receipts, certificates). Files live on the private
 * `documents` disk and are only reachable through short-lived signed URLs.
 */
class DocumentService
{
    public const DISK = 'documents';

    /** Renders a Blade view to PDF and stores it as a document (or replaces an existing one). */
    public function generate(
        Model $documentable,
        User $user,
        DocumentType $type,
        string $view,
        array $data,
        DocumentStatus $status = DocumentStatus::Available,
        ?Document $replace = null,
        ?string $name = null,
    ): Document {
        $pdf = Pdf::loadView($view, $data)->setPaper('a4');
        $output = $pdf->output();
        $pageCount = $this->pageCount($pdf);

        $path = sprintf('%d/%s-%s.pdf', $user->id, $type->value, Str::uuid());
        Storage::disk(self::DISK)->put($path, $output);

        $attributes = [
            'user_id' => $user->id,
            'type' => $type,
            'name' => $name ?? $type->label(),
            'status' => $status,
            'disk' => self::DISK,
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($output),
            'hash' => hash('sha256', $output),
            'page_count' => $pageCount,
        ];

        if ($replace !== null) {
            $oldPath = $replace->path;
            $replace->forceFill($attributes)->save();

            if ($oldPath && $oldPath !== $path) {
                Storage::disk(self::DISK)->delete($oldPath);
            }

            return $replace;
        }

        $document = new Document($attributes);
        $document->documentable()->associate($documentable);
        $document->save();

        return $document;
    }

    /** A document entry without a file yet (e.g. post-dated cheque issued offline). */
    public function placeholder(Model $documentable, User $user, DocumentType $type, DocumentStatus $status, ?string $name = null): Document
    {
        return $documentable->morphMany(Document::class, 'documentable')->firstOrCreate(
            ['type' => $type],
            ['user_id' => $user->id, 'name' => $name ?? $type->label(), 'status' => $status, 'disk' => self::DISK],
        );
    }

    /** @return array{url: string, filename: string, expires_at: Carbon} */
    public function temporaryDownload(Document $document, ?string $reference = null): array
    {
        $expiresAt = now()->addMinutes((int) config('amcom.documents.signed_url_ttl_minutes'));

        return [
            'url' => URL::temporarySignedRoute('documents.file', $expiresAt, ['document' => $document->id]),
            'filename' => $this->filename($document, $reference),
            'expires_at' => $expiresAt,
        ];
    }

    public function previewUrl(Document $document): string
    {
        return URL::temporarySignedRoute(
            'documents.preview',
            now()->addMinutes((int) config('amcom.documents.preview_url_ttl_minutes')),
            ['document' => $document->id],
        );
    }

    public function stream(Document $document, bool $inline): StreamedResponse
    {
        $disk = Storage::disk($document->disk);
        $filename = $this->filename($document);

        return $inline
            ? $disk->response($document->path, $filename, ['Content-Type' => $document->mime_type ?? 'application/pdf', 'Cache-Control' => 'private, no-store'])
            : $disk->download($document->path, $filename, ['Content-Type' => $document->mime_type ?? 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    public function contents(Document $document): ?string
    {
        return $document->hasFile() ? Storage::disk($document->disk)->get($document->path) : null;
    }

    public function filename(Document $document, ?string $reference = null): string
    {
        $reference ??= $document->documentable?->getAttribute('reference');

        return 'amcom-'.Str::slug($document->name).($reference ? '-'.$reference : '').'.pdf';
    }

    private function pageCount(\Barryvdh\DomPDF\PDF $pdf): ?int
    {
        try {
            return $pdf->getDomPDF()->getCanvas()->get_page_count();
        } catch (\Throwable) {
            return null;
        }
    }
}
