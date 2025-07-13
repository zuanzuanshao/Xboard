<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            \Log::info("Payment notify started", ['method' => $method, 'uuid' => $uuid]);
            
            // 记录完整的请求信息
            \Log::info("Webhook request details", [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'headers' => $request->headers->all(),
                'raw_body_length' => strlen($request->getContent()),
                'raw_body_preview' => substr($request->getContent(), 0, 500)
            ]);
            
            $paymentService = new PaymentService($method, null, $uuid);
            $params = $request->input();
            $params['raw_body'] = $request->getContent();
            $params['headers'] = $request->headers->all();
            \Log::info("Payment params prepared", ['has_raw_body' => !empty($params['raw_body'])]);
            $verify = $paymentService->notify($params);
            \Log::info("Payment verify result", ['verify' => $verify]);
            if (!$verify)
                return $this->fail([422, 'verify error']);
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                return $this->fail([400, 'handle error']);
            }
            \Log::info("Payment notify success");
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            \Log::error("Payment notify error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'status' => 'fail', 
                'message' => $e->getMessage(),
                'data' => null,
                'error' => get_class($e)
            ], 500);
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return $this->fail([400202, 'order is not found']);
        }
        if ($order->status !== Order::STATUS_PENDING)
            return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        $payment = Payment::where('id', $order->payment_id)->first();
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款%s元\n" .
            "———————————————\n" .
            "支付接口：%s\n" .
            "支付渠道：%s\n" .
            "本站订单：`%s`"
            ,
            $order->total_amount / 100,
            $payment->payment,
            $payment->name,
            $order->trade_no
        );
        
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
}
