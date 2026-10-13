<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Rules;
use App\Http\Resources\AuthUserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordResetService;
use App\Support\ApiResponse;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §4 — /api/v1/auth/* */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PasswordResetService $passwords,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $session = $this->auth->register($request->validated('email'), $request->validated('password'), $request);

        return ApiResponse::created($this->sessionPayload($session), 'Account created successfully.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->boolean('remember'),
            $request->validated('two_factor_code'),
            $request,
        );

        if ($result['type'] === 'challenge') {
            return ApiResponse::success($result['challenge'], 'Two-factor code sent.');
        }

        return ApiResponse::success($this->sessionPayload($result['session']), 'Login successful.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new AuthUserResource($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return ApiResponse::success(null, 'Logged out successfully.');
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate(['code' => Rules::code()]);

        $user = $this->auth->verifyEmail($request->user(), $request->input('code'));

        return ApiResponse::success(new AuthUserResource($user), 'Email verified successfully.');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $this->auth->sendEmailVerificationCode($request->user());
        }

        return ApiResponse::success(null, 'A new verification code has been sent.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $this->passwords->sendCode($request->input('email'));

        return ApiResponse::success(null, 'If an account exists for that email, we have sent a reset code.');
    }

    public function verifyResetCode(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255'], 'code' => Rules::code()]);

        $token = $this->passwords->verifyCode($request->input('email'), $request->input('code'));

        return ApiResponse::success([
            'reset_token' => $token['reset_token'],
            'expires_at' => Format::iso($token['expires_at']),
        ], 'Code verified.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwords->reset($request->validated('email'), $request->validated('reset_token'), $request->validated('password'));

        return ApiResponse::success(null, 'Your password has been reset. Please sign in.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword($request->user(), $request->validated('current_password'), $request->validated('password'));

        return ApiResponse::success(null, 'Your password has been changed.');
    }

    private function sessionPayload(array $session): array
    {
        return [
            'user' => (new AuthUserResource($session['user']))->resolve(request()),
            'token' => $session['token'],
            'token_type' => 'Bearer',
            'expires_at' => Format::iso($session['expires_at']),
        ];
    }
}
