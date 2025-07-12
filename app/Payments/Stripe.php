<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use Stripe\Stripe as StripeClient;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Webhook;

class StripeALLInOne implements PaymentInterface
{
    protected $config;
    
    public function __construct($config)
    {
        $this->config = $config;
    }
    
    public function form(): array
    {
        return [
            'currency' => [
                'label' => '货币单位',
                'description' => '请使用符合ISO 4217标准的三位字母，例如USD、EUR、GBP、CNY',
                'type' => 'input',
                'default' => 'USD'
            ],
            'stripe_sk_live' => [
                'label' => 'Stripe Secret Key',
                'description' => 'Stripe 密钥 (sk_test_... 或 sk_live_...)',
                'type' => 'input',
            ],
            'stripe_pk_live' => [
                'label' => 'Stripe Publishable Key',
                'description' => 'Stripe 可发布密钥 (pk_test_... 或 pk_live_...)',
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => 'WebHook密钥签名',
                'description' => 'Stripe Webhook 端点密钥 (whsec_....)',
                'type' => 'input',
            ],
            'description' => [
                'label' => '自定义商品介绍',
                'description' => '订单商品描述信息',
                'type' => 'input',
                'default' => 'VPN订阅服务'
            ],
            'payment_methods' => [
                'label' => '支付方式',
                'description' => '选择支持的支付方式',
                'type' => 'select',
                'select_options' => [
                    'card' => '信用卡/借记卡',
                    'alipay' => '支付宝',
                    'wechat_pay' => '微信支付',
                    'card,alipay' => '信用卡 + 支付宝',
                    'card,wechat_pay' => '信用卡 + 微信支付',
                    'alipay,wechat_pay' => '支付宝 + 微信支付',
                    'card,alipay,wechat_pay' => '全部支付方式',
                ],
                'default' => 'card'
            ],
            'auto_currency_convert' => [
                'label' => '自动货币转换',
                'description' => '是否启用从CNY到设定货币的自动转换',
                'type' => 'select',
                'select_options' => [
                    '1' => '启用',
                    '0' => '禁用'
                ],
                'default' => '1'
            ]
        ];
    }
    
    public function pay($order): array
    {
        try {
            // 初始化 Stripe 客户端
            StripeClient::setApiKey($this->config['stripe_sk_live']);
            $stripe = new \Stripe\StripeClient($this->config['stripe_sk_live']);
            
            // 处理货币和金额
            $currency = strtolower($this->config['currency'] ?? 'USD');
            $amount = $order['total_amount'];
            
            // 货币转换（如果启用）
            if (($this->config['auto_currency_convert'] ?? '1') === '1' && $currency !== 'cny') {
                $exchange = $this->exchange('CNY', strtoupper($currency));
                if (!$exchange) {
                    throw new ApiException('Currency conversion has timed out, please try again later', 500);
                }
                $amount = floor($amount * $exchange);
            }
            
            // 获取支付方式配置
            $paymentMethods = $this->config['payment_methods'] ?? 'card';
            $methods = array_map('trim', explode(',', $paymentMethods));
            
            // 基础支付意图参数
            $paymentIntentData = [
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => [
                    'user_id' => $order['user_id'] ?? '',
                    'out_trade_no' => $order['trade_no'],
                    'order_id' => $order['trade_no'],
                ],
                'statement_descriptor_suffix' => 'sub-' . $order['user_id'] . '-' . substr($order['trade_no'], -8),
                'description' => $this->config['description'] ?? 'VPN订阅服务',
            ];
            
            // 根据支付方式配置不同的参数
            if (count($methods) === 1 && in_array($methods[0], ['alipay', 'wechat_pay'])) {
                // 单一第三方支付方式
                return $this->handleSinglePaymentMethod($stripe, $paymentIntentData, $methods[0], $order);
            } elseif (count($methods) === 1 && $methods[0] === 'card') {
                // 纯信用卡支付
                return $this->handleCardPayment($stripe, $paymentIntentData, $order);
            } else {
                // 多种支付方式组合
                return $this->handleMultiplePaymentMethods($stripe, $paymentIntentData, $methods, $order);
            }
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new ApiException('Stripe API error: ' . $e->getMessage(), 500);
        } catch (\Exception $e) {
            throw new ApiException('Payment creation failed: ' . $e->getMessage(), 500);
        }
    }
    
    private function handleSinglePaymentMethod($stripe, $paymentIntentData, $method, $order): array
    {
        // 创建特定的支付方式
        $stripePaymentMethod = $stripe->paymentMethods->create(['type' => $method]);
        
        $paymentIntentData['confirm'] = true;
        $paymentIntentData['payment_method'] = $stripePaymentMethod->id;
        $paymentIntentData['return_url'] = $order['return_url'];
        
        // 为微信支付添加特定选项
        if ($method === 'wechat_pay') {
            $paymentIntentData['payment_method_options'] = [
                'wechat_pay' => ['client' => 'web']
            ];
        }
        
        $paymentIntent = $stripe->paymentIntents->create($paymentIntentData);
        
        if (!$paymentIntent->next_action) {
            throw new ApiException('Payment gateway request failed - no next action');
        }
        
        $nextAction = $paymentIntent->next_action;
        
        switch ($method) {
            case 'alipay':
                if (isset($nextAction->alipay_handle_redirect)) {
                    return [
                        'type' => 1, // URL redirect
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
                throw new ApiException('Unsupported payment method: ' . $method, 500);
        }
    }
    
    private function handleCardPayment($stripe, $paymentIntentData, $order): array
    {
        // 使用 Checkout Session 处理信用卡支付
        $checkoutSession = $stripe->checkout->sessions->create([
            'success_url' => $order['return_url'],
            'cancel_url' => $order['return_url'],
            'client_reference_id' => $order['trade_no'],
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $paymentIntentData['currency'],
                        'unit_amount' => $paymentIntentData['amount'],
                        'product_data' => [
                            'name' => $paymentIntentData['statement_descriptor_suffix'],
                            'description' => $paymentIntentData['description'],
                        ]
                    ],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'metadata' => $paymentIntentData['metadata'],
        ]);
        
        return [
            'type' => 1, // URL redirect
            'data' => $checkoutSession->url
        ];
    }
    
    private function handleMultiplePaymentMethods($stripe, $paymentIntentData, $methods, $order): array
    {
        // 支持多种支付方式的现代化实现
        $paymentIntentData['payment_method_types'] = $methods;
        
        // 为包含第三方支付的组合添加return_url
        if (in_array('alipay', $methods) || in_array('wechat_pay', $methods)) {
            $paymentIntentData['return_url'] = $order['return_url'];
        }
        
        // 为微信支付添加选项
        if (in_array('wechat_pay', $methods)) {
            $paymentIntentData['payment_method_options'] = [
                'wechat_pay' => ['client' => 'web']
            ];
        }
        
        $paymentIntent = $stripe->paymentIntents->create($paymentIntentData);
        
        // 返回客户端集成所需的数据
        return [
            'type' => 2, // 客户端集成
            'data' => [
                'client_secret' => $paymentIntent->client_secret,
                'publishable_key' => $this->config['stripe_pk_live'],
                'payment_intent_id' => $paymentIntent->id,
                'amount' => $paymentIntentData['amount'],
                'currency' => $paymentIntentData['currency'],
                'payment_methods' => $methods,
                'return_url' => $order['return_url'],
            ]
        ];
    }
    
    public function notify($params): array|bool
    {
        try {
            StripeClient::setApiKey($this->config['stripe_sk_live']);
            
            // 处理 payload 获取
            $payload = $params['payload'] ?? $GLOBALS['HTTP_RAW_POST_DATA'] ?? '';
            $signatureHeader = $params['stripe_signature'] ?? $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
            
            // 如果没有从参数获取到，尝试从headers获取
            if (!$signatureHeader) {
                $headers = getallheaders();
                $signatureHeader = $headers['Stripe-Signature'] ?? '';
            }
            
            if (!$payload || !$signatureHeader) {
                throw new ApiException('Missing payload or signature', 400);
            }
            
            // 验证 webhook 签名
            $event = Webhook::constructEvent(
                $payload,
                $signatureHeader,
                $this->config['stripe_webhook_key']
            );

        } catch (\UnexpectedValueException $e) {
            throw new ApiException('Error parsing payload: ' . $e->getMessage(), 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            throw new ApiException('Invalid signature: ' . $e->getMessage(), 400);
        } catch (\Exception $e) {
            throw new ApiException('Webhook processing failed: ' . $e->getMessage(), 400);
        }
        
        // 处理不同类型的事件
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $object = $event->data->object;
                if ($object->status === 'succeeded') {
                    // 兼容新旧两种metadata格式
                    $tradeNo = $object->metadata->out_trade_no ?? $object->metadata->order_id ?? null;
                    if (!$tradeNo) {
                        error_log('Stripe webhook: Missing trade number in metadata');
                        return false;
                    }
                    
                    return [
                        'trade_no' => $tradeNo,
                        'callback_no' => $object->id,
                        'amount' => $object->amount,
                        'currency' => $object->currency,
                    ];
                }
                break;
                
            case 'checkout.session.completed':
                $object = $event->data->object;
                if ($object->payment_status === 'paid') {
                    return [
                        'trade_no' => $object->client_reference_id,
                        'callback_no' => $object->payment_intent,
                        'amount' => $object->amount_total,
                        'currency' => $object->currency,
                    ];
                }
                break;
                
            case 'checkout.session.async_payment_succeeded':
                $object = $event->data->object;
                return [
                    'trade_no' => $object->client_reference_id,
                    'callback_no' => $object->payment_intent,
                    'amount' => $object->amount_total,
                    'currency' => $object->currency,
                ];
                
            case 'payment_intent.payment_failed':
                // 支付失败处理
                error_log('Stripe payment failed: ' . $event->data->object->id);
                return false;
                
            case 'payment_intent.requires_action':
                // 需要额外操作（常见于支付宝/微信支付）
                return false;
                
            case 'payment_method.attached':
                // 支付方式附加事件（支付宝/微信支付）
                return false;
                
            default:
                // 记录未处理的事件类型
                error_log('Unhandled Stripe event type: ' . $event->type);
                return false;
        }
        
        return false;
    }

    private function exchange($from, $to)
    {
        try {
            $from = strtolower($from);
            $to = strtolower($to);
            
            // 如果是相同货币，直接返回1
            if ($from === $to) {
                return 1;
            }
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Mozilla/5.0 (compatible; StripePayment/1.0)'
                ]
            ]);
            
            $result = file_get_contents(
                "https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/" . $from . ".min.json",
                false,
                $context
            );
            
            if ($result === false) {
                error_log('Currency exchange API request failed');
                return false;
            }
            
            $data = json_decode($result, true);
            if (!$data || !isset($data[$from][$to])) {
                error_log("Currency exchange rate not found for {$from} to {$to}");
                return false;
            }
            
            return $data[$from][$to];
        } catch (\Exception $e) {
            error_log('Currency exchange error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取支付状态
     */
    public function getPaymentStatus($paymentIntentId)
    {
        try {
            StripeClient::setApiKey($this->config['stripe_sk_live']);
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            return $paymentIntent->status;
        } catch (\Exception $e) {
            error_log('Get payment status error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 取消支付
     */
    public function cancelPayment($paymentIntentId)
    {
        try {
            StripeClient::setApiKey($this->config['stripe_sk_live']);
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            
            if (in_array($paymentIntent->status, ['requires_payment_method', 'requires_confirmation'])) {
                return PaymentIntent::cancel($paymentIntentId);
            }
            
            return false;
        } catch (\Exception $e) {
            error_log('Cancel payment error: ' . $e->getMessage());
            return false;
        }
    }
} 