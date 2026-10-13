<?php

namespace Tests\Feature\Integrations;

use App\Models\User;
use App\Notifications\UserEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ZeptoMailTransportTest extends TestCase
{
    public function test_notifications_are_delivered_through_the_zeptomail_api(): void
    {
        config(['mail.default' => 'zeptomail', 'mail.from.address' => 'noreply@amcom.test', 'mail.from.name' => 'Amcom']);
        Http::fake(['api.zeptomail.com/*' => Http::response(['request_id' => 'zm-123', 'message' => 'OK'], 201)]);

        $user = User::factory()->create(['email' => 'ada@example.com', 'first_name' => 'Ada']);
        $user->notifyNow(new UserEvent('password_changed', ['time' => 'now']), ['mail']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.zeptomail.com/v1.1/email'
                && $request->hasHeader('Authorization', 'Zoho-enczapikey zepto-test-key')
                && $request['from']['address'] === 'noreply@amcom.test'
                && $request['to'][0]['email_address']['address'] === 'ada@example.com'
                && $request['subject'] === 'Your password was changed'
                && str_contains($request['htmlbody'], 'Hello Ada');
        });
    }

    public function test_attachments_are_base64_encoded(): void
    {
        config(['mail.default' => 'zeptomail']);
        Http::fake(['api.zeptomail.com/*' => Http::response(['request_id' => 'zm-1'], 201)]);

        Notification::route('mail', 'lead@example.com')->notifyNow(
            new UserEvent('inspection_booked', ['name' => 'Lead', 'reference' => 'INS-1', 'property_title' => 'Flat', 'inspection_type' => 'virtual', 'scheduled_label' => 'Mon'], [], [
                ['name' => 'invite.ics', 'mime' => 'text/calendar', 'content' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"],
            ]),
        );

        Http::assertSent(fn ($request) => $request['attachments'][0]['name'] === 'invite.ics'
            && $request['attachments'][0]['mime_type'] === 'text/calendar'
            && base64_decode($request['attachments'][0]['content']) === "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
    }

    public function test_provider_errors_are_logged_and_raised_for_queue_retry(): void
    {
        config(['mail.default' => 'zeptomail']);
        Http::fake(['api.zeptomail.com/*' => Http::response(['error' => ['code' => 'TM_4001', 'message' => 'Invalid token']], 401)]);

        $this->expectException(\Throwable::class);

        User::factory()->create()->notifyNow(new UserEvent('welcome'), ['mail']);
    }
}
