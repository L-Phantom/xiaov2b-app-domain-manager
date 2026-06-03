<?php

namespace App\Http\Controllers\V2\App;

use App\Http\Controllers\V1\User\OrderController as LegacyOrderController;
use App\Http\Requests\User\OrderSave;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Http\Request;

class OrderController extends BaseController
{
    private const STATUS_LABELS = [
        0 => 'pending',
        1 => 'processing',
        2 => 'cancelled',
        3 => 'paid',
        4 => 'expired',
    ];

    public function index(Request $request)
    {
        $query = Order::query()
            ->where('user_id', $request->user['id'])
            ->orderBy('created_at', 'DESC');

        if ($request->input('status') !== null) {
            $query->where('status', $request->input('status'));
        }

        $items = $query->get()->map(function ($order) {
            return $this->transformOrder($order);
        })->values();

        return $this->success([
            'items' => $items,
            'summary' => [
                'pending_count' => $items->where('status', 0)->count(),
                'paid_count' => $items->where('status', 3)->count(),
                'cancelled_count' => $items->where('status', 2)->count(),
            ],
        ]);
    }

    public function paymentMethods()
    {
        $methods = Payment::query()
            ->select([
                'id',
                'name',
                'payment',
                'icon',
                'handling_fee_fixed',
                'handling_fee_percent',
            ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'payment' => $item->payment,
                    'icon' => $item->icon,
                    'handling_fee_fixed' => $item->handling_fee_fixed,
                    'handling_fee_percent' => $item->handling_fee_percent,
                ];
            })
            ->values();

        return $this->success([
            'items' => $methods,
        ]);
    }

    public function create(OrderSave $request, LegacyOrderController $legacyOrderController)
    {
        return $this->legacy(function () use ($request, $legacyOrderController) {
            return $legacyOrderController->save($request);
        }, 'ok', function ($payload) use ($request) {
            $tradeNo = $payload['data'] ?? null;
            $order = $tradeNo
                ? Order::query()->where('trade_no', $tradeNo)->where('user_id', $request->user['id'])->first()
                : null;
            $status = $order ? $order->status : 0;
            return [
                'trade_no' => $tradeNo,
                'status' => $status,
                'status_label' => self::STATUS_LABELS[$status] ?? 'pending',
                'order' => $order ? $this->transformOrder($order) : null,
            ];
        });
    }

    public function renew(OrderSave $request, LegacyOrderController $legacyOrderController)
    {
        return $this->create($request, $legacyOrderController);
    }

    public function checkout(Request $request, LegacyOrderController $legacyOrderController)
    {
        return $this->legacy(function () use ($request, $legacyOrderController) {
            return $legacyOrderController->checkout($request);
        }, 'ok', function ($payload) {
            return [
                'type' => $payload['type'] ?? null,
                'data' => $payload['data'] ?? null,
            ];
        });
    }

    public function show($tradeNo, Request $request)
    {
        $order = Order::query()
            ->where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();

        if (!$order) {
            return $this->error('Order does not exist', 40401, 404);
        }

        return $this->success($this->transformOrder($order, true));
    }

    public function status($tradeNo, Request $request, LegacyOrderController $legacyOrderController)
    {
        $request->merge(['trade_no' => $tradeNo]);
        return $this->legacy(function () use ($request, $legacyOrderController) {
            return $legacyOrderController->check($request);
        }, 'ok', function ($payload) use ($tradeNo) {
            $status = $payload['data'] ?? null;
            return [
                'trade_no' => $tradeNo,
                'status' => $status,
                'status_label' => self::STATUS_LABELS[$status] ?? 'unknown',
            ];
        });
    }

    public function cancel($tradeNo, Request $request, LegacyOrderController $legacyOrderController)
    {
        $request->merge(['trade_no' => $tradeNo]);
        return $this->legacy(function () use ($request, $legacyOrderController) {
            return $legacyOrderController->cancel($request);
        });
    }

    private function transformOrder(Order $order, bool $detailed = false): array
    {
        $plan = $order->plan_id ? Plan::find($order->plan_id) : null;
        $payment = $order->payment_id ? Payment::find($order->payment_id) : null;

        $data = [
            'trade_no' => $order->trade_no,
            'status' => $order->status,
            'status_label' => self::STATUS_LABELS[$order->status] ?? 'unknown',
            'type' => $order->type,
            'period' => $order->period,
            'total_amount' => $order->total_amount,
            'handling_amount' => $order->handling_amount,
            'discount_amount' => $order->discount_amount,
            'surplus_amount' => $order->surplus_amount,
            'refund_amount' => $order->refund_amount,
            'balance_amount' => $order->balance_amount,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
            'can_cancel' => (int) $order->status === 0,
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
            ] : null,
            'payment' => $payment ? [
                'id' => $payment->id,
                'name' => $payment->name,
                'payment' => $payment->payment,
            ] : null,
        ];

        if ($detailed) {
            $data['coupon_id'] = $order->coupon_id;
            $data['commission_balance'] = $order->commission_balance;
            $data['invite_user_id'] = $order->invite_user_id;
            $data['surplus_order_ids'] = $order->surplus_order_ids;
        }

        return $data;
    }
}
