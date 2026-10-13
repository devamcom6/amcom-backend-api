<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PayableType;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\AuditLogger;
use App\Services\Payments\PaymentService;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §15.6 — transactions.view / transactions.manage */
class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $from = $list->date('from');
        $to = $list->date('to');

        $query = Transaction::query()
            ->with('user')
            ->when($list->filter('status', PaymentStatus::values()), fn ($q, $v) => $q->whereIn('status', $v))
            ->when($list->filter('payable_type', PayableType::values()), fn ($q, $v) => $q->whereIn('payable_type', $v))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->when($list->search(), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$term}%")
                ->orWhere('provider_reference', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$term}%"))));

        $list->applySort($query, [
            'created_at' => 'created_at',
            'paid_at' => 'paid_at',
            'amount' => 'amount',
        ], '-created_at');

        return ApiResponse::paginated($list->paginate($query, 25), TransactionResource::class);
    }

    public function requery(Request $request, string $reference, PaymentService $payments, AuditLogger $audit): JsonResponse
    {
        $transaction = Transaction::query()->where('reference', $reference)->firstOrFail();
        $before = $transaction->status;

        $transaction = $payments->requery($transaction);

        $audit->log('transaction.requeried', $transaction, ['status' => $before->value], ['status' => $transaction->status->value], 're-queried payment');

        $message = $before === $transaction->status
            ? "Paystack confirms the payment is {$transaction->status->value}."
            : "Payment updated from {$before->value} to {$transaction->status->value}.";

        return ApiResponse::success(new TransactionResource($transaction->load('user')), $message);
    }
}
