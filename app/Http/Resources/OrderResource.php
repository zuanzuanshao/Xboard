<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Filter sensitive payment data - only keep safe fields for display
        if (isset($data['payment']) && is_array($data['payment'])) {
            $data['payment'] = [
                'id' => $data['payment']['id'] ?? null,
                'name' => $data['payment']['name'] ?? null,
                'payment' => $data['payment']['payment'] ?? null,
                'icon' => $data['payment']['icon'] ?? null,
                'handling_fee_fixed' => $data['payment']['handling_fee_fixed'] ?? null,
                'handling_fee_percent' => $data['payment']['handling_fee_percent'] ?? null,
            ];
        }

        return [
            ...$data,
            'period' => PlanService::getLegacyPeriod((string)$this->period),
            'plan' => $this->whenLoaded('plan', fn() => PlanResource::make($this->plan)),
        ];
    }
}
