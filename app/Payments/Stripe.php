<?php

namespace App\Payments;

use Stripe\Stripe as StripeClient;
use Stripe\PaymentIntent;
use Stripe\Webhook;

class Stripe
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
        StripeClient::setApiKey($this->config['secret_key']);
    }

    public function form()
    {
        return [
            'publishable_key' => [
                'label' => 'Publishable Key',
                'description' => 'Stripe Publishable Key (pk_test_... or pk_live_...)',
                'type' => 'input',
            ],
            'secret_key' => [
                'label' => 'Secret Key',
                'description' => 'Stripe Secret Key (sk_test_... or sk_live_...)',
                'type' => 'input',
            ],
            'webhook_secret' => [
                'label' => 'Webhook Secret',
                'description' => 'Stripe Webhook Endpoint Secret (whsec_...)',
                'type' => 'input',
            ],
            'currency' => [
                'label' => 'Currency',
                'description' => 'Payment currency (USD, EUR, GBP, CNY, etc.)',
                'type' => 'input',
                'default' => 'USD'
            ],
            'payment_methods' => [
                'label' => 'Payment Methods',
                'description' => 'Select supported payment methods',
                'type' => 'select',
                'select_options' => [
                    'card' => 'Credit/Debit Cards',
                    'alipay' => 'Alipay',
                    'wechat_pay' => 'WeChat Pay',
                    'card,alipay' => 'Cards + Alipay',
                    'card,wechat_pay' => 'Cards + WeChat Pay',
                    'card,alipay,wechat_pay' => 'Cards + Alipay + WeChat Pay',
                ],
                'default' => 'card'
            ],
        ];
    }

    public function pay($order)
    {
        try {
            // Get selected payment methods
            $paymentMethods = $this->config['payment_methods'] ?? 'card';
            $methods = explode(',', $paymentMethods);
            
            $currency = $this->config['currency'] ?? 'USD';
            $amount = $order['total_amount'];
            
            // Create payment intent configuration
            $paymentIntentData = [
                'amount' => $amount,
                'currency' => strtolower($currency),
                'metadata' => [
                    'order_id' => $order['trade_no'],
                    'user_id' => $order['user_id'] ?? null,
                ],
            ];

            // Configure payment methods based on selection
            if (in_array('alipay', $methods) || in_array('wechat_pay', $methods)) {
                // For Alipay and WeChat Pay, use specific payment method configuration
                $paymentIntentData['payment_method_types'] = $methods;
                
                // For Alipay, return_url is required
                if (in_array('alipay', $methods)) {
                    $paymentIntentData['return_url'] = $order['return_url'];
                }
                
                // For WeChat Pay, we need to set the client for mobile/web
                if (in_array('wechat_pay', $methods)) {
                    $paymentIntentData['payment_method_options'] = [
                        'wechat_pay' => [
                            'client' => 'web', // or 'mobile' based on your needs
                        ]
                    ];
                }
            } else {
                // For card payments only
                $paymentIntentData['automatic_payment_methods'] = [
                    'enabled' => true,
                ];
            }

            $paymentIntent = PaymentIntent::create($paymentIntentData);

            // Different response types based on payment method
            if (in_array('alipay', $methods) && count($methods) === 1) {
                // Pure Alipay - redirect to Alipay
                $nextAction = $paymentIntent->next_action;
                if ($nextAction && $nextAction->type === 'redirect_to_url') {
                    return [
                        'type' => 1, // URL redirect
                        'data' => $nextAction->redirect_to_url->url
                    ];
                }
            } elseif (in_array('wechat_pay', $methods) && count($methods) === 1) {
                // Pure WeChat Pay - show QR code
                $nextAction = $paymentIntent->next_action;
                if ($nextAction && $nextAction->type === 'wechat_pay_display_qr_code') {
                    return [
                        'type' => 0, // QR code
                        'data' => $nextAction->wechat_pay_display_qr_code->data
                    ];
                }
            }

            // Default: return client secret for frontend integration
            return [
                'type' => 2, // Custom type for Stripe Elements
                'data' => [
                    'client_secret' => $paymentIntent->client_secret,
                    'publishable_key' => $this->config['publishable_key'],
                    'payment_intent_id' => $paymentIntent->id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'payment_methods' => $methods,
                    'return_url' => $order['return_url'],
                ]
            ];
        } catch (\Exception $e) {
            throw new \Exception('Stripe payment creation failed: ' . $e->getMessage());
        }
    }

    public function notify($params)
    {
        try {
            // Verify webhook signature
            $payload = $params['payload'] ?? '';
            $sigHeader = $params['stripe_signature'] ?? '';
            
            if (!$payload || !$sigHeader) {
                return false;
            }

            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $this->config['webhook_secret']
            );

            // Handle the event
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $paymentIntent = $event->data->object;
                    
                    return [
                        'trade_no' => $paymentIntent->metadata->order_id,
                        'callback_no' => $paymentIntent->id,
                        'amount' => $paymentIntent->amount,
                        'currency' => $paymentIntent->currency,
                    ];
                    
                case 'payment_intent.payment_failed':
                    // Handle failed payment
                    return false;
                    
                case 'payment_method.attached':
                    // Handle payment method attached (for Alipay/WeChat Pay)
                    return false; // Not a payment completion event
                    
                case 'payment_intent.requires_action':
                    // Handle payment requiring additional action (common with Alipay/WeChat Pay)
                    return false; // Not a payment completion event
                    
                default:
                    // Unhandled event type
                    return false;
            }
        } catch (\Exception $e) {
            // Log error or handle exception
            error_log('Stripe webhook error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get payment status
     */
    public function getPaymentStatus($paymentIntentId)
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            return $paymentIntent->status;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Cancel payment
     */
    public function cancelPayment($paymentIntentId)
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            if ($paymentIntent->status === 'requires_payment_method') {
                return PaymentIntent::cancel($paymentIntentId);
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}