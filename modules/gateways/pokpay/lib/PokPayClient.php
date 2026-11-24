<?php
/**
 * PokPay REST API client (dependency-free, cURL based).
 *
 * Endpoints (see https://docs.pokpay.io/rest-api and the official
 * rpay/pokpay-payments-sdk):
 *   POST /auth/sdk/login                                         -> data.accessToken
 *   POST /merchants/{merchantId}/sdk-orders                      -> data.sdkOrder (+ _self.confirmUrl)
 *   GET  /merchants/{merchantId}/sdk-orders/{sdkOrderId}         -> data.sdkOrder (authenticated)
 *   GET  /sdk-orders/{sdkOrderId}                                -> data.sdkOrder (public)
 *   POST /merchants/{merchantId}/sdk-orders/{sdkOrderId}/refund  -> data.sdkOrder
 */

namespace PokPayWhmcs;

class PokPayException extends \RuntimeException
{
    /** @var int */
    private $httpStatus;

    /** @var mixed */
    private $body;

    public function __construct($message, $httpStatus = 0, $body = null)
    {
        parent::__construct($message);
        $this->httpStatus = (int) $httpStatus;
        $this->body = $body;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function getBody()
    {
        return $this->body;
    }
}

class PokPayClient
{
    const PRODUCTION_URL = 'https://api.pokpay.io';
    const STAGING_URL = 'https://api-staging.pokpay.io';

    /** @var string */
    private $baseUrl;
    /** @var string */
    private $keyId;
    /** @var string */
    private $keySecret;
    /** @var string */
    private $merchantId;
    /** @var callable|null fn(string $action, $request, $response, array $secrets) */
    private $logger;
    /** @var int */
    private $timeout;

    /** @var string|null */
    private $accessToken;
    /** @var int */
    private $tokenExpiresAt = 0;

    public function __construct($keyId, $keySecret, $merchantId, $sandbox = false, $logger = null, $baseUrlOverride = '', $timeout = 30)
    {
        $this->keyId = trim((string) $keyId);
        $this->keySecret = trim((string) $keySecret);
        $this->merchantId = trim((string) $merchantId);
        $this->logger = $logger;
        $this->timeout = (int) $timeout;

        $override = trim((string) $baseUrlOverride);
        if ($override !== '') {
            $this->baseUrl = rtrim($override, '/');
        } else {
            $this->baseUrl = $sandbox ? self::STAGING_URL : self::PRODUCTION_URL;
        }
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    public function getMerchantId()
    {
        return $this->merchantId;
    }

    /**
     * Authenticate and return a bearer token (cached for the lifetime of this object).
     */
    public function login()
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt - 30) {
            return $this->accessToken;
        }

        if ($this->keyId === '' || $this->keySecret === '') {
            throw new PokPayException('PokPay Key ID / Key Secret are not configured.');
        }

        $response = $this->request('POST', '/auth/sdk/login', [
            'keyId' => $this->keyId,
            'keySecret' => $this->keySecret,
        ], false, 'login');

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        if (empty($data['accessToken'])) {
            throw new PokPayException('PokPay login did not return an access token. Check that the keys match the selected environment (staging keys only work in Sandbox mode).', 0, $response);
        }

        $this->accessToken = (string) $data['accessToken'];

        // Docs examples disagree on expiresIn units, so prefer expiresAt; fall back to 10 minutes.
        $expiresAt = 0;
        if (!empty($data['expiresAt'])) {
            $ts = strtotime((string) $data['expiresAt']);
            if ($ts !== false) {
                $expiresAt = $ts;
            }
        }
        $this->tokenExpiresAt = $expiresAt > time() ? $expiresAt : time() + 600;

        return $this->accessToken;
    }

    /**
     * Create an SDK order. Returns the sdkOrder array (contains id and _self.confirmUrl).
     */
    public function createOrder(array $payload)
    {
        $this->requireMerchant();
        $response = $this->request(
            'POST',
            '/merchants/' . rawurlencode($this->merchantId) . '/sdk-orders',
            $payload,
            true,
            'createOrder'
        );

        $order = self::extractOrder($response);
        if (empty($order['id'])) {
            throw new PokPayException('PokPay did not return an order id.', 0, $response);
        }

        return $order;
    }

    /**
     * Retrieve an SDK order. Uses the authenticated merchant endpoint and falls back
     * to the public endpoint if the former is unavailable.
     */
    public function getOrder($sdkOrderId)
    {
        $sdkOrderId = (string) $sdkOrderId;
        if ($sdkOrderId === '') {
            throw new PokPayException('Missing PokPay order id.');
        }

        $firstError = null;
        if ($this->merchantId !== '') {
            try {
                $response = $this->request(
                    'GET',
                    '/merchants/' . rawurlencode($this->merchantId) . '/sdk-orders/' . rawurlencode($sdkOrderId),
                    null,
                    true,
                    'getOrder'
                );
                $order = self::extractOrder($response);
                if (!empty($order['id'])) {
                    return $order;
                }
            } catch (PokPayException $e) {
                $firstError = $e;
                // 401/403 means bad credentials: do not mask that with the public endpoint.
                if (in_array($e->getHttpStatus(), [401, 403], true)) {
                    throw $e;
                }
            }
        }

        $response = $this->request('GET', '/sdk-orders/' . rawurlencode($sdkOrderId), null, false, 'getOrderPublic');
        $order = self::extractOrder($response);
        if (empty($order['id'])) {
            throw $firstError ?: new PokPayException('PokPay order not found.', 0, $response);
        }

        // The public endpoint is unauthenticated: make sure the order belongs to us.
        if ($this->merchantId !== '' && isset($order['merchant']['id'])
            && (string) $order['merchant']['id'] !== $this->merchantId) {
            throw new PokPayException('PokPay order belongs to a different merchant.', 0, $order);
        }

        return $order;
    }

    /**
     * Refund an SDK order. If $amount is null the order is fully refunded.
     */
    public function refundOrder($sdkOrderId, $amount = null, $reason = 'Refund')
    {
        $this->requireMerchant();
        $payload = ['refundReason' => (string) $reason !== '' ? (string) $reason : 'Refund'];
        if ($amount !== null) {
            $payload['refundAmount'] = round((float) $amount, 2);
        }

        $response = $this->request(
            'POST',
            '/merchants/' . rawurlencode($this->merchantId) . '/sdk-orders/' . rawurlencode((string) $sdkOrderId) . '/refund',
            $payload,
            true,
            'refundOrder'
        );

        return self::extractOrder($response) ?: (isset($response['data']) ? (array) $response['data'] : []);
    }

    // ---------------------------------------------------------------------
    // Subscriptions (https://docs.pokpay.io/docs/subscriptions.md)
    // ---------------------------------------------------------------------

    /**
     * Create a subscription plan. Returns data.subscriptionPlan, which includes the
     * plan's webhookSecret (only returned on create / clone / rotate).
     */
    public function createPlan(array $payload)
    {
        $this->requireMerchant();
        $response = $this->request(
            'POST',
            '/merchants/' . rawurlencode($this->merchantId) . '/subscription-plans',
            $payload,
            true,
            'createPlan'
        );

        $plan = isset($response['data']['subscriptionPlan']) && is_array($response['data']['subscriptionPlan'])
            ? $response['data']['subscriptionPlan'] : [];
        if (empty($plan['id'])) {
            throw new PokPayException('PokPay did not return a subscription plan id.', 0, $response);
        }

        return $plan;
    }

    /**
     * Create a (pending) subscription on a plan. Returns the whole data object:
     * ['subscription' => [... '_self' => ['confirmUrl']], 'plan' => [...], 'charge' => [...], 'sdkOrder' => [...]]
     */
    public function createSubscription($planId, $externalCustomerId = null, $expiresAfterMinutes = null)
    {
        $this->requireMerchant();
        $payload = ['planId' => (string) $planId];
        if ($externalCustomerId !== null && $externalCustomerId !== '') {
            $payload['externalCustomerId'] = substr((string) $externalCustomerId, 0, 255);
        }
        if ($expiresAfterMinutes !== null) {
            $payload['expiresAfterMinutes'] = (int) $expiresAfterMinutes;
        }

        $response = $this->request(
            'POST',
            '/merchants/' . rawurlencode($this->merchantId) . '/subscriptions',
            $payload,
            true,
            'createSubscription'
        );

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        if (empty($data['subscription']['id'])) {
            throw new PokPayException('PokPay did not return a subscription id.', 0, $response);
        }

        return $data;
    }

    /**
     * Pull the sdkOrder object out of an API (or webhook) response.
     */
    public static function extractOrder($response)
    {
        if (!is_array($response)) {
            return [];
        }
        if (isset($response['data']['sdkOrder']) && is_array($response['data']['sdkOrder'])) {
            return $response['data']['sdkOrder'];
        }
        if (isset($response['sdkOrder']) && is_array($response['sdkOrder'])) {
            return $response['sdkOrder'];
        }
        if (isset($response['data']) && is_array($response['data']) && isset($response['data']['id'])) {
            return $response['data'];
        }
        if (isset($response['id'])) {
            return $response;
        }
        return [];
    }

    /**
     * Extract the confirmation (hosted payment page) URL from an order.
     */
    public static function confirmUrl(array $order)
    {
        foreach (['_self', 'self'] as $key) {
            if (!empty($order[$key]['confirmUrl'])) {
                return (string) $order[$key]['confirmUrl'];
            }
        }
        return '';
    }

    private function requireMerchant()
    {
        if ($this->merchantId === '') {
            throw new PokPayException('PokPay Merchant ID is not configured.');
        }
    }

    /**
     * @return array decoded JSON body
     */
    private function request($method, $path, $body, $authenticated, $action)
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: PokPay-WHMCS/' . (defined('POKPAY_WHMCS_VERSION') ? POKPAY_WHMCS_VERSION : 'dev'),
        ];
        if ($authenticated) {
            $headers[] = 'Authorization: Bearer ' . $this->login();
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $encoded = null;
        if ($body !== null) {
            $encoded = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        $this->log($action, ['method' => $method, 'url' => $url, 'body' => $body], $raw === false ? $error : $raw);

        if ($raw === false || $errno) {
            throw new PokPayException('Could not connect to PokPay: ' . $error, 0);
        }

        $apiStatus = is_array($decoded) && isset($decoded['statusCode']) ? (int) $decoded['statusCode'] : $status;
        if ($status >= 400 || $apiStatus >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) && !empty($decoded['message'])
                ? (is_array($decoded['message']) ? implode('; ', $decoded['message']) : (string) $decoded['message'])
                : 'Unexpected response from PokPay';
            throw new PokPayException('PokPay API error (HTTP ' . $status . '): ' . $message, $status ?: $apiStatus, $decoded ?: $raw);
        }

        return $decoded;
    }

    private function log($action, $request, $response)
    {
        if (!is_callable($this->logger)) {
            return;
        }
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $response = json_encode(self::redact($decoded));
            }
        }
        $secrets = array_values(array_filter([$this->keySecret, $this->accessToken]));
        call_user_func($this->logger, $action, self::redact($request), $response, $secrets);
    }

    private static function redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if (in_array((string) $key, ['accessToken', 'refreshToken', 'keySecret', 'webhookSecret'], true)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }
}
