<?php

use App\Services\Investments\BuybackService;
use App\Services\ScheduledTasks;
use Illuminate\Support\Facades\Artisan;

/*
| Scheduled maintenance commands (scheduled in bootstrap/app.php → withSchedule).
| Run the scheduler with `php artisan schedule:work` (dev) or a cron calling `schedule:run`.
*/

Artisan::command('buyback:release-reservations', function (BuybackService $buyback) {
    $this->info('Released '.$buyback->releaseExpiredReservations().' expired reservation(s).');
})->purpose('Cancel unpaid buyback orders whose plot reservation has lapsed');

Artisan::command('investments:mature', function (ScheduledTasks $tasks) {
    $this->info('Matured '.$tasks->matureInvestments().' investment(s).');
})->purpose('Mark active investments whose maturity date has arrived as matured');

Artisan::command('notifications:maturity-reminders', function (ScheduledTasks $tasks) {
    $this->info('Sent '.$tasks->maturityReminders().' maturity reminder(s).');
})->purpose('Remind customers about upcoming investment maturities');

Artisan::command('notifications:renewal-reminders', function (ScheduledTasks $tasks) {
    $this->info('Sent '.$tasks->renewalReminders().' renewal reminder(s).');
})->purpose('Remind tenants/lessees about renewals and update holding statuses');

Artisan::command('notifications:roi-updates', function (ScheduledTasks $tasks) {
    $this->info('Sent '.$tasks->roiUpdates().' ROI update(s).');
})->purpose('Send monthly portfolio summaries');

Artisan::command('inspections:send-reminders', function (ScheduledTasks $tasks) {
    $this->info('Sent '.$tasks->inspectionReminders().' inspection reminder(s).');
})->purpose('Send reminders for inspections happening in ~24 hours');

Artisan::command('accounts:anonymise {--days=30}', function (ScheduledTasks $tasks) {
    $this->info('Anonymised '.$tasks->anonymiseDeletedAccounts((int) $this->option('days')).' account(s).');
})->purpose('Anonymise accounts whose deletion grace period has passed');
