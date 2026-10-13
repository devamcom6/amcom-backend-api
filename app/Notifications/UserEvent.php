<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * A templated business-event notification (email via ZeptoMail + optional in-app record).
 * Queued so API responses never wait on email delivery.
 */
class UserEvent extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    /**
     * @param  array<string, mixed>  $data  placeholder values
     * @param  list<int>  $documentIds  private documents to attach
     * @param  list<array{name: string, mime: string, content: string}>  $rawAttachments
     */
    public function __construct(
        public readonly string $event,
        public readonly array $data = [],
        public readonly array $documentIds = [],
        public readonly array $rawAttachments = [],
    ) {
        Templates::get($event); // fail fast on unknown templates
    }

    public function via(object $notifiable): array
    {
        $template = Templates::get($this->event);
        $channels = [];

        $mailAllowed = ! $notifiable instanceof User
            || $template['preference'] === null
            || $notifiable->wantsNotification($template['preference']);

        if ($mailAllowed) {
            $channels[] = 'mail';
        }

        if ($template['in_app'] && $notifiable instanceof User) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = Templates::get($this->event);
        $data = $this->data + ['name' => $notifiable instanceof User ? $notifiable->displayName() : ($this->data['name'] ?? 'there')];

        $message = (new MailMessage)
            ->subject(Templates::render($template['subject'], $data))
            ->greeting('Hello '.($notifiable instanceof User ? ($notifiable->first_name ?: $notifiable->displayName()) : ($this->data['name'] ?? 'there')).',');

        foreach ($template['lines'] as $line) {
            $rendered = trim(Templates::render($line, $data));
            if ($rendered !== '') {
                $message->line($rendered);
            }
        }

        if ($template['action_text'] && ($path = $this->actionPath($template, $data))) {
            $message->action($template['action_text'], $this->frontendUrl($path));
        }

        foreach ($this->documentIds as $documentId) {
            $document = Document::find($documentId);
            if ($document?->hasFile() && Storage::disk($document->disk)->exists($document->path)) {
                $message->attachData(
                    Storage::disk($document->disk)->get($document->path),
                    str($document->name)->slug().'.pdf',
                    ['mime' => $document->mime_type ?? 'application/pdf'],
                );
            }
        }

        foreach ($this->rawAttachments as $attachment) {
            $message->attachData($attachment['content'], $attachment['name'], ['mime' => $attachment['mime']]);
        }

        return $message->salutation('— The Amcom team');
    }

    /** In-app notification payload (contract §12). */
    public function toArray(object $notifiable): array
    {
        $template = Templates::get($this->event);
        $data = $this->data;

        return [
            'event' => $this->event,
            'category' => $template['category'],
            'title' => Templates::render($template['title'] ?? $template['subject'], $data),
            'body' => Templates::render($template['body'] ?? $template['lines'][0], $data),
            'action_url' => $this->actionPath($template, $data),
        ];
    }

    private function actionPath(array $template, array $data): ?string
    {
        if (! $template['action_path']) {
            return null;
        }

        $path = Templates::render($template['action_path'], $data);

        // action_url must be a frontend path (contract §12); drop anything else.
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, ':') ? $path : null;
    }

    private function frontendUrl(string $path): string
    {
        return config('amcom.frontend_url').$path;
    }
}
