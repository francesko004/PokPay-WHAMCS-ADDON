<?php
/**
 * POK subscriptions support: plan registry and subscription records.
 *
 * POK plans are immutable (amount, currency, frequency can never change) and each
 * plan has its own webhook secret, returned only once at creation. The module
 * therefore creates one POK plan per (name, amount, currency, frequency) on demand
 * and stores its secret encrypted with WHMCS's encrypt().
 *
 * @see https://docs.pokpay.io/docs/subscriptions.md
 */

namespace PokPayWhmcs;

use WHMCS\Database\Capsule;

require_once __DIR__ . '/Gateway.php';

class Subscriptions
{
    const PLANS_TABLE = 'mod_pokpay_plans';
    const SUBSCRIPTIONS_TABLE = 'mod_pokpay_subscriptions';

    /**
     * WHMCS billing cycle => POK plan frequency. Other WHMCS cycles
     * (Semi-Annually, Biennially, Triennially) have no POK equivalent.
     */
    const CYCLES = [
        'monthly' => 'monthly',
        'quarterly' => 'quarterly',
        'annually' => 'annually',
    ];

    /** Minimum amount per cycle, per POK docs. */
    const MIN_AMOUNT = ['ALL' => 100, 'EUR' => 1];

    /**
     * Plan URLs are frozen by POK once a customer subscribes, so these paths are
     * permanent. They carry no query string because POK appends ?subscriptionId=...
     */
    const WEBHOOK_PATH = 'modules/gateways/callback/pokpay_subscription.php';
    const SUCCESS_PATH = 'modules/gateways/pokpay/subscription-success.php';
    const FAIL_PATH = 'modules/gateways/pokpay/subscription-failed.php';

    private static function truncate($text, $length)
    {
        return function_exists('mb_substr') ? mb_substr((string) $text, 0, $length) : substr((string) $text, 0, $length);
    }

    public static function frequencyFor($billingCycle)
    {
        $key = strtolower(str_replace(['-', ' '], '', (string) $billingCycle));
        return isset(self::CYCLES[$key]) ? self::CYCLES[$key] : null;
    }

    /**
     * Can POK run a subscription for this amount/currency/cycle?
     * Returns [bool, string $reason].
     */
    public static function isEligible($amount, $currency, $billingCycle)
    {
        $currency = strtoupper((string) $currency);
        if (self::frequencyFor($billingCycle) === null) {
            return [false, 'Billing cycle ' . $billingCycle . ' is not supported by POK subscriptions'];
        }
        if (!isset(self::MIN_AMOUNT[$currency])) {
            return [false, 'POK subscriptions support ALL and EUR only'];
        }
        if (round((float) $amount, 2) < self::MIN_AMOUNT[$currency]) {
            return [false, 'Minimum subscription amount is ' . self::MIN_AMOUNT[$currency] . ' ' . $currency];
        }
        return [true, 'OK'];
    }

    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    public static function ensureTables()
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::PLANS_TABLE)) {
            $schema->create(self::PLANS_TABLE, function ($table) {
                $table->increments('id');
                $table->string('plan_key', 64)->unique();
                $table->string('plan_id', 64)->unique();
                $table->string('name', 255);
                $table->decimal('amount', 16, 2);
                $table->string('currency', 3);
                $table->string('frequency', 16);
                $table->boolean('sandbox')->default(false);
                $table->text('webhook_secret'); // encrypted
                $table->timestamps();
            });
        }
        if (!$schema->hasTable(self::SUBSCRIPTIONS_TABLE)) {
            $schema->create(self::SUBSCRIPTIONS_TABLE, function ($table) {
                $table->increments('id');
                $table->string('subscription_id', 64)->unique();
                $table->string('plan_id', 64)->index();
                $table->integer('client_id')->index();
                $table->integer('service_id')->index();
                $table->string('status', 20)->default('pending');
                $table->text('confirm_url');
                $table->dateTime('next_charge_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private static function planKey($name, $amount, $currency, $frequency, $sandbox)
    {
        return hash('sha256', implode('|', [
            $sandbox ? 'staging' : 'production',
            trim((string) $name),
            number_format((float) $amount, 2, '.', ''),
            strtoupper((string) $currency),
            (string) $frequency,
        ]));
    }

    /**
     * Find the POK plan for this price, creating it on POK first if needed.
     * Returns the stored plan row (webhook_secret still encrypted).
     */
    public static function getOrCreatePlan(array $params, $name, $amount, $currency, $billingCycle)
    {
        self::ensureTables();

        list($ok, $reason) = self::isEligible($amount, $currency, $billingCycle);
        if (!$ok) {
            throw new PokPayException($reason);
        }

        $frequency = self::frequencyFor($billingCycle);
        $currency = strtoupper((string) $currency);
        $amount = round((float) $amount, 2);
        $sandbox = !empty($params['sandbox']) && $params['sandbox'] !== 'off';
        $name = trim((string) $name) !== '' ? trim((string) $name) : 'Hosting';
        $key = self::planKey($name, $amount, $currency, $frequency, $sandbox);

        $row = Capsule::table(self::PLANS_TABLE)->where('plan_key', $key)->first();
        if ($row) {
            return (array) $row;
        }

        // Plan URLs can never be changed later, so refuse to freeze a bad one.
        $base = Gateway::systemUrl($params);
        if (!preg_match('#^https?://[^/]+/#i', $base)) {
            throw new PokPayException('WHMCS System URL is not set to an absolute URL; cannot create a POK plan.');
        }
        $plan = Gateway::client($params)->createPlan([
            'name' => self::truncate($name, 255),
            'description' => self::truncate($name . ' - ' . $amount . ' ' . $currency . ' / ' . $frequency, 1000),
            'amount' => $amount,
            'currencyCode' => $currency,
            'frequency' => $frequency,
            'trialDays' => null,
            'totalCycles' => null,
            'webhookUrl' => $base . self::WEBHOOK_PATH,
            'redirectUrl' => $base . self::SUCCESS_PATH,
            'failRedirectUrl' => $base . self::FAIL_PATH,
        ]);

        if (empty($plan['webhookSecret'])) {
            throw new PokPayException('PokPay did not return a webhook secret for the new plan.', 0, $plan);
        }

        $now = date('Y-m-d H:i:s');
        Capsule::table(self::PLANS_TABLE)->insert([
            'plan_key' => $key,
            'plan_id' => (string) $plan['id'],
            'name' => $name,
            'amount' => $amount,
            'currency' => $currency,
            'frequency' => $frequency,
            'sandbox' => $sandbox,
            'webhook_secret' => encrypt((string) $plan['webhookSecret']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (array) Capsule::table(self::PLANS_TABLE)->where('plan_key', $key)->first();
    }

    /**
     * Decrypted webhook secret for a POK plan id, or null if the plan isn't ours.
     */
    public static function planSecret($planId)
    {
        self::ensureTables();
        $encrypted = Capsule::table(self::PLANS_TABLE)->where('plan_id', (string) $planId)->value('webhook_secret');
        return $encrypted ? (string) decrypt($encrypted) : null;
    }

    public static function storeSubscription(array $subscription, $planId, $clientId, $serviceId)
    {
        self::ensureTables();
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::SUBSCRIPTIONS_TABLE)->insert([
            'subscription_id' => (string) $subscription['id'],
            'plan_id' => (string) $planId,
            'client_id' => (int) $clientId,
            'service_id' => (int) $serviceId,
            'status' => isset($subscription['status']) ? (string) $subscription['status'] : 'pending',
            'confirm_url' => isset($subscription['_self']['confirmUrl']) ? (string) $subscription['_self']['confirmUrl'] : '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function findSubscription($subscriptionId)
    {
        self::ensureTables();
        $row = Capsule::table(self::SUBSCRIPTIONS_TABLE)->where('subscription_id', (string) $subscriptionId)->first();
        return $row ? (array) $row : null;
    }
}
