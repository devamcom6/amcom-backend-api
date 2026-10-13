<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Symfony mailer transport registered as `zeptomail` (MAIL_MAILER=zeptomail).
 * Converts a Laravel/Symfony Email into the ZeptoMail API payload.
 */
class ZeptoMailTransport extends AbstractTransport
{
    public function __construct(private readonly ZeptoMailService $zepto)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('ZeptoMail can only send MIME messages.');
        }

        $email = MessageConverter::toEmail($original);
        $envelope = $message->getEnvelope();

        $from = $email->getFrom()[0] ?? null;
        $defaultFrom = $this->zepto->defaultFrom();

        $payload = [
            'from' => [
                'address' => $from?->getAddress() ?: $defaultFrom['address'],
                'name' => $from?->getName() ?: $defaultFrom['name'],
            ],
            'to' => $this->recipients($email->getTo() ?: $this->envelopeRecipients($envelope)),
            'subject' => (string) $email->getSubject(),
        ];

        if ($cc = $email->getCc()) {
            $payload['cc'] = $this->recipients($cc);
        }

        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = $this->recipients($bcc);
        }

        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = array_map(fn (Address $a) => array_filter(['address' => $a->getAddress(), 'name' => $a->getName()]), $replyTo);
        }

        if (($html = $email->getHtmlBody()) !== null) {
            $payload['htmlbody'] = is_string($html) ? $html : stream_get_contents($html);
        }

        if (($text = $email->getTextBody()) !== null) {
            $payload['textbody'] = is_string($text) ? $text : stream_get_contents($text);
        }

        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $attachments[] = [
                'name' => $headers->getHeaderParameter('Content-Disposition', 'filename') ?? $attachment->getFilename() ?? 'attachment',
                'mime_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'content' => base64_encode($attachment->getBody()),
            ];
        }

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        $requestId = $this->zepto->send($payload);

        if ($requestId) {
            $message->setMessageId($requestId);
        }
    }

    /** @param list<Address> $addresses */
    private function recipients(array $addresses): array
    {
        return array_map(fn (Address $a) => [
            'email_address' => array_filter(['address' => $a->getAddress(), 'name' => $a->getName()]),
        ], $addresses);
    }

    /** @return list<Address> */
    private function envelopeRecipients(Envelope $envelope): array
    {
        return $envelope->getRecipients();
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }
}
