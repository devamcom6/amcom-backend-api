<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\PayoutAccountRequest;
use App\Http\Requests\Account\SupportTicketRequest;
use App\Http\Requests\Rules;
use App\Http\Resources\LoginActivityResource;
use App\Http\Resources\PayoutAccountResource;
use App\Http\Resources\ProfileResource;
use App\Models\LoginActivity;
use App\Models\PayoutAccount;
use App\Services\AccountService;
use App\Services\Auth\TwoFactorService;
use App\Services\Leads\LeadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** Contract §5.8–§5.13 — every query is scoped to the authenticated user (404 for others). */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    // ---------------------------------------------------------------- payout accounts

    public function payoutAccounts(Request $request): JsonResponse
    {
        $accounts = $request->user()->payoutAccounts()->orderByDesc('is_default')->oldest()->get();

        return ApiResponse::success(PayoutAccountResource::collection($accounts)->resolve($request));
    }

    public function resolvePayoutAccount(PayoutAccountRequest $request): JsonResponse
    {
        return ApiResponse::success($this->accounts->resolveAccount($request->validated('bank_code'), $request->validated('account_number')));
    }

    public function storePayoutAccount(PayoutAccountRequest $request): JsonResponse
    {
        $account = $this->accounts->addPayoutAccount($request->user(), $request->validated('bank_code'), $request->validated('account_number'));

        return ApiResponse::created(new PayoutAccountResource($account), 'Payout account added.');
    }

    public function destroyPayoutAccount(Request $request, int $id): JsonResponse
    {
        $account = PayoutAccount::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $this->accounts->removePayoutAccount($request->user(), $account);

        return ApiResponse::success(null, 'Payout account removed.');
    }

    // ---------------------------------------------------------------- preferences

    public function preferences(Request $request): JsonResponse
    {
        return ApiResponse::success($this->accounts->preferences($request->user()));
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['boolean'],
        ]);

        return ApiResponse::success($this->accounts->updatePreferences($request->user(), $validated['preferences']), 'Preferences updated.');
    }

    // ---------------------------------------------------------------- sessions

    public function sessions(Request $request): JsonResponse
    {
        $activities = $this->accounts->sessions($request->user());

        $alive = PersonalAccessToken::query()
            ->whereIn('id', $activities->pluck('token_id')->filter())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('id')
            ->flip();

        $activities->each(fn (LoginActivity $a) => $a->setAttribute('token_alive', $a->token_id !== null && isset($alive[$a->token_id])));

        return ApiResponse::success(LoginActivityResource::collection($activities)->resolve($request));
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $activity = LoginActivity::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $this->accounts->revokeSession($request->user(), $activity);

        return ApiResponse::success(null, 'Session signed out.');
    }

    // ---------------------------------------------------------------- two-factor

    public function enableTwoFactor(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        return ApiResponse::success($twoFactor->enable($request->user()), 'Verification code sent.');
    }

    public function confirmTwoFactor(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $request->validate(['code' => Rules::code()]);

        return ApiResponse::success(['recovery_codes' => $twoFactor->confirm($request->user(), $request->input('code'))], 'Two-factor authentication enabled.');
    }

    public function disableTwoFactor(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:128']]);
        $twoFactor->disable($request->user(), $request->input('password'));

        return ApiResponse::success(null, 'Two-factor authentication disabled.');
    }

    // ---------------------------------------------------------------- phone change

    public function requestPhoneChange(Request $request): JsonResponse
    {
        $request->validate(['phone' => Rules::phone()]);

        return ApiResponse::success($this->accounts->requestPhoneChange($request->user(), $request->input('phone')), 'Verification code sent.');
    }

    public function confirmPhoneChange(Request $request): JsonResponse
    {
        $request->validate(['phone' => Rules::phone(), 'code' => Rules::code()]);

        $user = $this->accounts->confirmPhoneChange($request->user(), $request->input('phone'), $request->input('code'));

        return ApiResponse::success(new ProfileResource($user), 'Phone number updated.');
    }

    // ---------------------------------------------------------------- deletion & support

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['confirmation' => ['required', 'string', 'in:DELETE']], ['confirmation.in' => 'Type DELETE to confirm.']);

        $this->accounts->scheduleDeletion($request->user());

        return ApiResponse::success(null, 'Your account deletion has been scheduled.');
    }

    public function supportTicket(SupportTicketRequest $request, LeadService $leads): JsonResponse
    {
        $ticket = $leads->supportTicket($request->user(), $request->validated());

        return ApiResponse::created(['reference' => $ticket->reference], 'We received your request.');
    }
}
