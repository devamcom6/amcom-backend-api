<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PayableType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitializePaymentRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\Payments\PaymentService;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §11.2, §11.3, §11.5 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function initialize(InitializePaymentRequest $request): JsonResponse
    {
        $transaction = $this->payments->initialize(
            $request->user(),
            PayableType::from($request->validated('payable_type')),
            (int) $request->validated('payable_id'),
            PaymentMethod::from($request->validated('method')),
            $request->validated('callback_url'),
        );

        return ApiResponse::created([
            'reference' => $transaction->reference,
            'authorization_url' => $transaction->authorization_url,
            'access_code' => $transaction->access_code,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'expires_at' => null,
        ], 'Payment initialised.');
    }

    /** Stored status, no Paystack round-trip. */
    public function show(Request $request, string $reference): JsonResponse
    {
        $transaction = $this->payments->findForUser($request->user(), $reference);

        return ApiResponse::success($this->payments->verificationPayload($transaction));
    }

    /** Re-verifies with Paystack when the webhook has not settled the payment yet. */
    public function verify(Request $request, string $reference): JsonResponse
    {
        $transaction = $this->payments->refresh($this->payments->findForUser($request->user(), $reference));

        return ApiResponse::success($this->payments->verificationPayload($transaction));
    }

    public function history(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->with('user')
            ->when($list->filter('status', PaymentStatus::values()), fn ($q, $v) => $q->whereIn('status', $v))
            ->latest('id');

        return ApiResponse::paginated($list->paginate($query, 20), TransactionResource::class);
    }
}
