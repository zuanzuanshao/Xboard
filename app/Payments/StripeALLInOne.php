<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;

class StripeALLInOne implements PaymentInterface
{
    protected $config;
    protected $stripe;
    
    public function __construct($config)
    {
        $this->config = $config;
        $this->initializeStripe();
    }
    
    private function initializeStripe()
    {
        if (empty($this->config['stripe_sk_live'])) {
            throw new ApiException('Stripe secret key is required');
        }
        
        $this->stripe = new \Stripe\StripeClient($this->config['stripe_sk_live']);
        \Stripe\Stripe::setApiKey($this->config['stripe_sk_live']);
    }
    
    public function form(): array
    {
        return [
            'currency' => [
                'label' => '货币单位',
                'description' => '请使用符合ISO 4217标准的三位字母，例如USD, GBP, EUR',
                'type' => 'input',
                'placeholder' => 'USD',
                'default' => 'USD'
            ],
            'stripe_sk_live' => [
                'label' => 'Stripe Secret Key',
                'description' => 'Stripe API密钥 (sk_live_... 或 sk_test_...)',
                'type' => 'input',
                'placeholder' => 'sk_live_...',
            ],
            'stripe_pk_live' => [
                'label' => 'Stripe Publishable Key',
                'description' => 'Stripe可发布密钥 (pk_live_... 或 pk_test_...)',
                'type' => 'input',
                'placeholder' => 'pk_live_...',
            ],
            'stripe_webhook_key' => [
                'label' => 'WebHook密钥签名',
                'description' => 'Stripe Webhook签名密钥 (whsec_...)',
                'type' => 'input',
                'placeholder' => 'whsec_...',
            ],
            'description' => [
                'label' => '自定义商品介绍',
                'description' => '订单描述信息',
                'type' => 'input',
                'placeholder' => '订阅服务',
                'default' => '订阅服务'
            ],
            'payment_method' => [
                'label' => '支付方式',
                'description' => '选择支付方式',
                'type' => 'select',
                'select_options' => [
                    'cards' => '信用卡/借记卡 (Cards)',
                    'alipay' => '支付宝 (Alipay)',
                    'wechat_pay' => '微信支付 (WeChat Pay)',
                ],
                'default' => 'cards'
            ],
            'auto_capture' => [
                'label' => '自动确认付款',
                'description' => '是否自动确认付款，关闭后需要手动确认',
                'type' => 'select',
                'select_options' => [
                    'true' => '是',
                    'false' => '否'
                ],
                'default' => 'true'
            ]
        ];
    }
    
    public function pay($order): array
    {
        try {
            $currency = strtoupper($this->config['currency'] ?? 'USD');
            $amount = $order['total_amount'];
            
            // Convert CNY to target currency if needed
            if ($currency !== 'CNY') {
                $exchange = $this->exchange('CNY', $currency);
                if (!$exchange) {
                    throw new ApiException('Currency conversion failed, please try again later', 500);
                }
                $amount = floor($amount * $exchange);
            }
            
            // Validate minimum amount (Stripe minimum is $0.50 USD)
            $minAmount = $this->getMinimumAmount($currency);
            if ($amount < $minAmount) {
                throw new ApiException("Amount too small. Minimum is {$minAmount} {$currency}", 400);
            }
            
            Log::info('Stripe payment initiated', [
                'trade_no' => $order['trade_no'],
                'amount' => $amount,
                'currency' => $currency,
                'payment_method' => $this->config['payment_method']
            ]);
            
            $paymentMethod = $this->config['payment_method'] ?? 'cards';
            
            if ($paymentMethod === 'cards') {
                return $this->createCheckoutSession($order, $amount, $currency);
            } else {
                return $this->createPaymentIntent($order, $amount, $currency, $paymentMethod);
            }
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe API error', [
                'error' => $e->getMessage(),
                'trade_no' => $order['trade_no'] ?? 'unknown'
            ]);
            throw new ApiException('Payment gateway error: ' . $e->getMessage(), 500);
        } catch (\Exception $e) {
            Log::error('Stripe payment error', [
                'error' => $e->getMessage(),
                'trade_no' => $order['trade_no'] ?? 'unknown'
            ]);
            throw new ApiException('Payment processing failed: ' . $e->getMessage(), 500);
        }
    }
    
    private function createCheckoutSession($order, $amount, $currency): array
    {
        $session = $this->stripe->checkout->sessions->create([
            'success_url' => $order['return_url'] . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $order['return_url'] . '?cancelled=true',
            'client_reference_id' => $order['trade_no'],
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'unit_amount' => $amount,
                        'product_data' => [
                            'name' => $this->config['description'] ?? 'Subscription Service',
                            'description' => "Order: {$order['trade_no']}",
                        ]
                    ],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'metadata' => [
                'user_id' => $order['user_id'],
                'out_trade_no' => $order['trade_no'],
                'order_amount' => $order['total_amount']
            ],
            'expires_at' => time() + (30 * 60), // 30 minutes expiry
        ]);
        
        return [
            'type' => 1, // redirect
            'data' => $session->url
        ];
    }
    
    private function createPaymentIntent($order, $amount, $currency, $paymentMethod): array
    {
        $paymentMethodObj = $this->stripe->paymentMethods->create([
            'type' => $paymentMethod,
        ]);
        
        $params = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'confirm' => true,
            'payment_method' => $paymentMethodObj->id,
            'automatic_payment_methods' => ['enabled' => true],
            'statement_descriptor_suffix' => substr('Order-' . $order['trade_no'], -22),
            'description' => $this->config['description'] ?? 'Subscription Service',
            'metadata' => [
                'user_id' => $order['user_id'],
                'out_trade_no' => $order['trade_no'],
                'order_amount' => $order['total_amount']
            ],
            'return_url' => $order['return_url']
        ];
        
        // Add payment method specific options
        if ($paymentMethod === 'wechat_pay') {
            $params['payment_method_options'] = [
                'wechat_pay' => [
                    'client' => 'web'
                ],
            ];
        }
        
        // Set capture method based on config
        $params['capture_method'] = ($this->config['auto_capture'] ?? 'true') === 'true' ? 'automatic' : 'manual';
        
        $paymentIntent = $this->stripe->paymentIntents->create($params);
        
        if (!$paymentIntent->next_action) {
            throw new ApiException('Payment gateway request failed - no next action provided');
        }
        
        $nextAction = $paymentIntent->next_action;
        
        switch ($paymentMethod) {
            case 'alipay':
                if (isset($nextAction->alipay_handle_redirect)) {
                    return [
                        'type' => 1,
                        'data' => $nextAction->alipay_handle_redirect->url
                    ];
                }
                throw new ApiException('Unable to get Alipay redirect URL', 500);
                
            case 'wechat_pay':
                if (isset($nextAction->wechat_pay_display_qr_code)) {
                    return [
                        'type' => 0, // QR code
                        'data' => $nextAction->wechat_pay_display_qr_code->data
                    ];
                }
                throw new ApiException('Unable to get WeChat Pay QR code', 500);
                
            default:
                throw new ApiException('Unsupported payment method: ' . $paymentMethod, 400);
        }
    }
    
    private function getMinimumAmount($currency): int
    {
        // Stripe minimum amounts in cents/smallest currency unit
        $minimums = [
            'USD' => 50,   // $0.50
            'EUR' => 50,   // €0.50
            'GBP' => 30,   // £0.30
            'CAD' => 50,   // $0.50
            'AUD' => 50,   // $0.50
            'SGD' => 50,   // $0.50
            'JPY' => 50,   // ¥50
            'CNY' => 350,  // ¥3.50
        ];
        
        return $minimums[$currency] ?? 50;
    }
    
    public function notify($params): array|bool
    {
        try {
            Log::info("Stripe webhook notification started", [
                'has_webhook_key' => !empty($this->config['stripe_webhook_key']),
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
            ]);
            
            // Get raw POST data for Stripe signature verification
            $payload = file_get_contents('php://input');
            if (empty($payload)) {
                Log::error("Stripe webhook: Empty payload received");
                return false;
            }
            
            Log::info("Stripe webhook payload received", [
                'payload_length' => strlen($payload),
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
            ]);
            
            // Get Stripe signature from headers - handle different server environments
            $signatureHeader = $this->getStripeSignatureHeader();
            
            if (empty($signatureHeader)) {
                Log::warning("Stripe webhook: No signature header found");
            }
            
            // Verify webhook signature if configured
            if (!empty($this->config['stripe_webhook_key'])) {
                Log::info("Stripe webhook: Verifying signature");
                try {
                    $event = \Stripe\Webhook::constructEvent(
                        $payload,
                        $signatureHeader,
                        $this->config['stripe_webhook_key']
                    );
                    Log::info("Stripe webhook: Signature verified successfully");
                } catch (\Stripe\Exception\SignatureVerificationException $e) {
                    Log::error("Stripe webhook signature verification failed", [
                        'error' => $e->getMessage(),
                        'signature_header' => $signatureHeader
                    ]);
                    return false;
                }
            } else {
                Log::warning("Stripe webhook: No webhook key configured, skipping signature verification");
                $eventData = json_decode($payload, true);
                if (!$eventData) {
                    Log::error("Stripe webhook: Invalid JSON payload");
                    return false;
                }
                
                // Convert array to object for consistency
                $event = $this->arrayToObject($eventData);
            }
            
            Log::info("Stripe webhook event processed", [
                'event_id' => $event->id ?? 'unknown',
                'event_type' => $event->type ?? 'unknown',
                'event_created' => $event->created ?? 'unknown',
                'livemode' => $event->livemode ?? 'unknown'
            ]);
            
            // Process the event
            return $this->processWebhookEvent($event);
            
        } catch (\UnexpectedValueException $e) {
            Log::error("Stripe webhook JSON parse error", [
                'error' => $e->getMessage(),
                'payload_preview' => substr($payload ?? '', 0, 500)
            ]);
            return false;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error("Stripe webhook signature verification error", [
                'error' => $e->getMessage()
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error("Stripe webhook processing error", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }
    
    private function processWebhookEvent($event): array|bool
    {
        $eventType = $event->type;
        $object = $event->data->object;
        
        Log::info("Processing Stripe webhook event", [
            'event_type' => $eventType,
            'object_type' => $object->object ?? 'unknown',
            'object_id' => $object->id ?? 'unknown',
            'object_status' => $object->status ?? 'unknown'
        ]);
        
        switch ($eventType) {
            case 'payment_intent.succeeded':
                return $this->handlePaymentIntentSucceeded($object);
                
            case 'payment_intent.payment_failed':
                return $this->handlePaymentIntentFailed($object);
                
            case 'checkout.session.completed':
                return $this->handleCheckoutSessionCompleted($object);
                
            case 'checkout.session.async_payment_succeeded':
                return $this->handleCheckoutSessionAsyncPaymentSucceeded($object);
                
            case 'checkout.session.async_payment_failed':
                return $this->handleCheckoutSessionAsyncPaymentFailed($object);
                
            case 'payment_intent.requires_action':
                Log::info("Payment requires additional action", [
                    'payment_intent_id' => $object->id
                ]);
                return false; // Don't process as successful payment
                
            default:
                Log::warning("Unhandled Stripe webhook event type", [
                    'event_type' => $eventType,
                    'object_id' => $object->id ?? 'unknown'
                ]);
                return false; // Return false for unhandled events
        }
    }
    
    private function handlePaymentIntentSucceeded($object): array
    {
        if ($object->status !== 'succeeded') {
            Log::warning("Payment intent status is not succeeded", [
                'payment_intent_id' => $object->id,
                'status' => $object->status
            ]);
            return false;
        }
        
        // Check if metadata exists and has out_trade_no
        if (empty($object->metadata) || empty($object->metadata->out_trade_no)) {
            Log::error("Payment intent missing trade_no in metadata", [
                'payment_intent_id' => $object->id,
                'metadata' => $object->metadata ?? 'null'
            ]);
            return false;
        }
        
        $tradeNo = $object->metadata->out_trade_no;
        Log::info("Payment intent succeeded", [
            'payment_intent_id' => $object->id,
            'trade_no' => $tradeNo,
            'amount' => $object->amount,
            'currency' => $object->currency,
            'user_id' => $object->metadata->user_id ?? 'unknown'
        ]);
        
        return [
            'trade_no' => $tradeNo,
            'callback_no' => $object->id
        ];
    }
    
    private function handlePaymentIntentFailed($object): bool
    {
        Log::warning("Payment intent failed", [
            'payment_intent_id' => $object->id,
            'last_payment_error' => $object->last_payment_error ?? 'unknown'
        ]);
        return false;
    }
    
    private function handleCheckoutSessionCompleted($object): array|bool
    {
        if ($object->payment_status !== 'paid') {
            Log::warning("Checkout session not paid", [
                'session_id' => $object->id,
                'payment_status' => $object->payment_status
            ]);
            return false;
        }
        
        if (!$object->client_reference_id) {
            Log::error("Checkout session missing client_reference_id", [
                'session_id' => $object->id
            ]);
            return false;
        }
        
        Log::info("Checkout session completed", [
            'session_id' => $object->id,
            'trade_no' => $object->client_reference_id,
            'amount_total' => $object->amount_total ?? 0,
            'currency' => $object->currency ?? 'unknown'
        ]);
        
        return [
            'trade_no' => $object->client_reference_id,
            'callback_no' => $object->payment_intent
        ];
    }
    
    private function handleCheckoutSessionAsyncPaymentSucceeded($object): array|bool
    {
        if (!$object->client_reference_id) {
            Log::error("Checkout session missing client_reference_id", [
                'session_id' => $object->id
            ]);
            return false;
        }
        
        Log::info("Checkout session async payment succeeded", [
            'session_id' => $object->id,
            'trade_no' => $object->client_reference_id
        ]);
        
        return [
            'trade_no' => $object->client_reference_id,
            'callback_no' => $object->payment_intent
        ];
    }
    
    private function handleCheckoutSessionAsyncPaymentFailed($object): bool
    {
        Log::warning("Checkout session async payment failed", [
            'session_id' => $object->id,
            'trade_no' => $object->client_reference_id ?? 'unknown'
        ]);
        return false;
    }
    
    private function getStripeSignatureHeader(): string
    {
        // Try different methods to get the Stripe signature header
        $headers = [];
        
        // Method 1: getallheaders() if available
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Stripe-Signature'])) {
                return $headers['Stripe-Signature'];
            }
        }
        
        // Method 2: $_SERVER headers
        $serverHeaders = [
            'HTTP_STRIPE_SIGNATURE',
            'HTTP_STRIPE_SIGNATURE_',
            'STRIPE_SIGNATURE'
        ];
        
        foreach ($serverHeaders as $header) {
            if (isset($_SERVER[$header])) {
                return $_SERVER[$header];
            }
        }
        
        // Method 3: Manual header parsing
        if (isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            return $_SERVER['HTTP_STRIPE_SIGNATURE'];
        }
        
        // Method 4: Check apache_request_headers if available
        if (function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            if (isset($apacheHeaders['Stripe-Signature'])) {
                return $apacheHeaders['Stripe-Signature'];
            }
        }
        
        return '';
    }
    
    private function arrayToObject($array): object
    {
        $object = new \stdClass();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $object->$key = $this->arrayToObject($value);
            } else {
                $object->$key = $value;
            }
        }
        return $object;
    }

    private function exchange($from, $to)
    {
        $from = strtolower($from);
        $to = strtolower($to);
        
        // Same currency, no conversion needed
        if ($from === $to) {
            return 1.0;
        }
        
        $cacheKey = "stripe_exchange_rate_{$from}_{$to}";
        
        // Try to get from cache first (5 minutes cache)
        $cachedRate = cache()->get($cacheKey);
        if ($cachedRate !== null) {
            Log::info("Using cached exchange rate", [
                'from' => $from,
                'to' => $to,
                'rate' => $cachedRate
            ]);
            return $cachedRate;
        }
        
        try {
            // Primary API
            $url = "https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/{$from}.min.json";
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Xboard/1.0'
                ]
            ]);
            
            $result = file_get_contents($url, false, $context);
            if ($result === false) {
                throw new \Exception("Failed to fetch exchange rate from primary API");
            }
            
            $data = json_decode($result, true);
            if (!$data || !isset($data[$from][$to])) {
                throw new \Exception("Invalid response from currency API");
            }
            
            $rate = (float) $data[$from][$to];
            
            if ($rate <= 0) {
                throw new \Exception("Invalid exchange rate: {$rate}");
            }
            
            // Cache the result for 5 minutes
            cache()->put($cacheKey, $rate, 300);
            
            Log::info("Exchange rate fetched successfully", [
                'from' => $from,
                'to' => $to,
                'rate' => $rate
            ]);
            
            return $rate;
            
        } catch (\Exception $e) {
            Log::error("Exchange rate fetch failed", [
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            
            // Try fallback API
            try {
                return $this->getExchangeRateFallback($from, $to);
            } catch (\Exception $fallbackError) {
                Log::error("Fallback exchange rate also failed", [
                    'from' => $from,
                    'to' => $to,
                    'error' => $fallbackError->getMessage()
                ]);
                return null;
            }
        }
    }
    
    private function getExchangeRateFallback($from, $to)
    {
        // Fallback to fixed rates for common conversions
        $fixedRates = [
            'cny_usd' => 0.14,
            'cny_eur' => 0.13,
            'cny_gbp' => 0.11,
            'usd_cny' => 7.1,
            'eur_cny' => 7.7,
            'gbp_cny' => 9.0,
        ];
        
        $key = $from . '_' . $to;
        if (isset($fixedRates[$key])) {
            Log::warning("Using fixed exchange rate fallback", [
                'from' => $from,
                'to' => $to,
                'rate' => $fixedRates[$key]
            ]);
            return $fixedRates[$key];
        }
        
        // Try alternative API
        $url = "https://api.exchangerate-api.com/v4/latest/{$from}";
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'Xboard/1.0'
            ]
        ]);
        
        $result = file_get_contents($url, false, $context);
        if ($result === false) {
            throw new \Exception("Fallback API also failed");
        }
        
        $data = json_decode($result, true);
        if (!$data || !isset($data['rates'][$to])) {
            throw new \Exception("Invalid response from fallback API");
        }
        
        $rate = (float) $data['rates'][$to];
        
        if ($rate <= 0) {
            throw new \Exception("Invalid fallback exchange rate: {$rate}");
        }
        
        Log::info("Fallback exchange rate used", [
            'from' => $from,
            'to' => $to,
            'rate' => $rate
        ]);
        
        return $rate;
    }
} 