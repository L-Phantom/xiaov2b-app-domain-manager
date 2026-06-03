<?php

namespace App\Http\Controllers\V2\App;

use App\Models\Plan;
use App\Services\PlanService;

class PlanController extends BaseController
{
    private const PERIOD_MAP = [
        'month_price' => 'month',
        'quarter_price' => 'quarter',
        'half_year_price' => 'half_year',
        'year_price' => 'year',
        'two_year_price' => 'two_year',
        'three_year_price' => 'three_year',
        'onetime_price' => 'onetime',
        'reset_price' => 'reset',
    ];

    public function index()
    {
        $counts = PlanService::countActiveUsers();
        $plans = Plan::query()
            ->where('show', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        foreach ($plans as $plan) {
            if ($plan->capacity_limit === null) {
                continue;
            }
            if (!isset($counts[$plan->id])) {
                continue;
            }
            $plan->capacity_limit = $plan->capacity_limit - $counts[$plan->id]->count;
        }

        return $this->success([
            'items' => $plans->map(function ($plan) {
                return $this->transformPlan($plan);
            })->values(),
        ]);
    }

    public function show($id)
    {
        $plan = Plan::query()
            ->where('id', $id)
            ->where(function ($query) {
                $query->where('show', 1)->orWhere('renew', 1);
            })
            ->first();

        if (!$plan) {
            return $this->error('Subscription plan does not exist', 40401, 404);
        }

        return $this->success($this->transformPlan($plan));
    }

    private function transformPlan(Plan $plan): array
    {
        $periods = [];
        foreach (self::PERIOD_MAP as $field => $label) {
            $price = $plan->{$field};
            if ($price === null) {
                continue;
            }
            $periods[] = [
                'key' => $field,
                'label' => $label,
                'price' => $price,
            ];
        }

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'content' => $plan->content,
            'transfer_enable' => $plan->transfer_enable,
            'device_limit' => $plan->device_limit,
            'speed_limit' => $plan->speed_limit,
            'show' => (bool) $plan->show,
            'renew' => (bool) $plan->renew,
            'capacity_limit' => $plan->capacity_limit,
            'currency_symbol' => config('v2board.currency_symbol', '¥'),
            'periods' => $periods,
        ];
    }
}
