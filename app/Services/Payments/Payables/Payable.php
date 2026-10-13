<?php

namespace App\Services\Payments\Payables;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a customer can pay for. Each implementation owns its amount rules,
 * eligibility checks and the business effects of a verified payment.
 *
 * @template TModel of Model
 */
interface Payable
{
    /**
     * The payable owned by $user, or 404.
     *
     * @return TModel
     */
    public function find(User $user, int $id): Model;

    /**
     * Throws ApiException when the payable can't be paid right now.
     *
     * @param  TModel  $payable
     */
    public function assertPayable(Model $payable): void;

    /**
     * Authoritative amount in whole Naira (never taken from the client).
     *
     * @param  TModel  $payable
     */
    public function amount(Model $payable): int;

    /** @param  TModel  $payable */
    public function description(Model $payable): string;

    /**
     * Called when a payment is initialised (e.g. extend plot reservations).
     *
     * @param  TModel  $payable
     */
    public function onInitialized(Model $payable, Transaction $transaction): void;

    /**
     * Paystack reports the payment is in progress (e.g. bank transfer pending).
     *
     * @param  TModel  $payable
     */
    public function onProcessing(Model $payable, Transaction $transaction): void;

    /**
     * Applies the business effects of a verified successful payment. Runs inside a DB transaction.
     *
     * @param  TModel  $payable
     */
    public function onSuccess(Model $payable, Transaction $transaction): void;

    /**
     * Payment failed/abandoned: keep the payable payable. Runs inside a DB transaction.
     *
     * @param  TModel  $payable
     */
    public function onFailure(Model $payable, Transaction $transaction): void;

    /**
     * Post-commit side effects of success (documents, emails).
     *
     * @param  TModel  $payable
     */
    public function afterSuccess(Model $payable, Transaction $transaction): void;

    /**
     * Pre-formatted sections for the payment result screen (contract §11.3).
     *
     * @param  TModel  $payable
     */
    public function summarySections(Model $payable, Transaction $transaction): array;

    /**
     * Frontend path after success (null when unknown).
     *
     * @param  TModel  $payable
     */
    public function nextUrl(Model $payable): ?string;

    /** Frontend path while a payment is still pending. */
    public function pendingUrl(): string;

    /**
     * Frontend path to retry a failed payment.
     *
     * @param  TModel  $payable
     */
    public function retryPath(Model $payable): string;
}
