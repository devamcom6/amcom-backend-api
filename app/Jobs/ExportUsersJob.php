<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Builds a CSV of selected customers and emails it to the requesting admin. */
class ExportUsersJob implements ShouldQueue
{
    use Queueable;

    /** @param list<int> $userIds */
    public function __construct(public readonly int $adminId, public readonly array $userIds) {}

    public function handle(Notifier $notifier): void
    {
        $admin = User::find($this->adminId);

        if ($admin === null || ! $admin->hasPermission('users.view')) {
            return;
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['ID', 'Name', 'Email', 'Phone', 'Status', 'KYC', 'Email verified', 'Joined']);

        User::query()->customers()->whereIn('id', $this->userIds)->orderBy('id')->chunk(500, function ($users) use ($handle) {
            foreach ($users as $user) {
                fputcsv($handle, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, [
                    $user->id, $user->name, $user->email, $user->phone, $user->status->value, $user->kyc_status->value,
                    $user->email_verified_at ? 'yes' : 'no', $user->created_at?->toDateString(),
                ]));
            }
        });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $notifier->user($admin, 'export_ready', ['count' => count($this->userIds)], [], [
            ['name' => 'amcom-users-'.now()->format('Ymd-His').'.csv', 'mime' => 'text/csv', 'content' => $csv],
        ]);
    }
}
