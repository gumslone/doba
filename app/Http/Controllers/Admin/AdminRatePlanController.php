<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AdjustmentType;
use App\Http\Controllers\Controller;
use App\Models\BookingRoom;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminRatePlanController extends Controller
{
    /**
     * The §5 plan types. `package` (stay + board + treatments) is the one
     * §20 flags as a genuinely different sellable unit; it is offered here
     * as a label, not yet as bundled behaviour.
     */
    public const TYPES = ['standard', 'non_refundable', 'early_bird', 'long_stay', 'package'];

    public function index(): View
    {
        return view('admin.rate-plans.index', [
            'plans' => RatePlan::query()
                ->orderByDesc('priority')
                ->orderBy('id')
                ->with('translations')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.rate-plans.edit', [
            'plan' => new RatePlan([
                'adjustment_type' => AdjustmentType::Percent,
                'refundable' => true,
                'cancellation_hours' => 48,
                'is_active' => true,
            ]),
            'roomTypes' => $this->roomTypes(),
        ]);
    }

    public function edit(RatePlan $ratePlan): View
    {
        $ratePlan->load(['translations', 'roomTypes']);

        return view('admin.rate-plans.edit', [
            'plan' => $ratePlan,
            'roomTypes' => $this->roomTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new RatePlan);
    }

    public function update(Request $request, RatePlan $ratePlan): RedirectResponse
    {
        return $this->save($request, $ratePlan);
    }

    public function destroy(RatePlan $ratePlan): RedirectResponse
    {
        // A plan that has ever been sold is deactivated, never deleted:
        // booking_rooms still points at it, and although the terms are
        // snapshotted, the link is what lets staff see which rate a stay
        // was sold on.
        if (BookingRoom::query()->where('rate_plan_id', $ratePlan->id)->exists()) {
            $ratePlan->update(['is_active' => false]);

            return redirect('/admin/rate-plans')->with('saved', __('admin.plan_deactivated'));
        }

        $ratePlan->delete();

        return redirect('/admin/rate-plans')->with('saved', __('admin.deleted'));
    }

    protected function save(Request $request, RatePlan $plan): RedirectResponse
    {
        // Normalised BEFORE validation: the code is stored upper-cased, so
        // a unique rule checking the raw input would let "saver" through
        // against a stored "SAVER" and fail at the database instead.
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:64', 'alpha_dash',
                Rule::unique('rate_plans', 'code')->ignore($plan->id),
            ],
            'type' => ['required', Rule::in(self::TYPES)],
            'adjustment_type' => ['required', Rule::enum(AdjustmentType::class)],
            // Entered the way a human says it: "-12" percent, or "-15.00"
            // euros. Converted to basis points / minor units below.
            'adjustment_value' => ['required', 'numeric', 'between:-100,1000'],

            'min_nights' => ['nullable', 'integer', 'min:1', 'max:255'],
            'max_nights' => ['nullable', 'integer', 'min:1', 'max:255', 'gte:min_nights'],
            'min_days_before_arrival' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'max_days_before_arrival' => ['nullable', 'integer', 'min:0', 'max:9999', 'gte:min_days_before_arrival'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],

            'includes_breakfast' => ['sometimes', 'boolean'],
            'refundable' => ['sometimes', 'boolean'],
            'cancellation_hours' => ['required', 'integer', 'min:0', 'max:8760'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],

            'room_type_ids' => ['nullable', 'array'],
            'room_type_ids.*' => ['integer', 'exists:room_types,id'],

            'translations' => ['required', 'array'],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:2000'],
            'translations.*.policy_text' => ['nullable', 'string', 'max:5000'],
        ]);

        $type = AdjustmentType::from($validated['adjustment_type']);
        $value = (float) $validated['adjustment_value'];

        DB::transaction(function () use ($plan, $validated, $type, $value): void {
            $plan->fill([
                'code' => $validated['code'],
                'type' => $validated['type'],
                'adjustment_type' => $type,
                // Percent → basis points (−12 becomes −1200) so no
                // fractional rate ever needs a float; fixed → minor units.
                'adjustment_value' => (int) round($value * 100),
                'min_nights' => $validated['min_nights'] ?? null,
                'max_nights' => $validated['max_nights'] ?? null,
                'min_days_before_arrival' => $validated['min_days_before_arrival'] ?? null,
                'max_days_before_arrival' => $validated['max_days_before_arrival'] ?? null,
                'valid_from' => $validated['valid_from'] ?? null,
                'valid_to' => $validated['valid_to'] ?? null,
                'includes_breakfast' => (bool) ($validated['includes_breakfast'] ?? false),
                'refundable' => (bool) ($validated['refundable'] ?? false),
                'cancellation_hours' => (int) $validated['cancellation_hours'],
                'is_active' => (bool) ($validated['is_active'] ?? false),
                'priority' => (int) ($validated['priority'] ?? 0),
            ])->save();

            // Empty selection = offered with every room type.
            $plan->roomTypes()->sync($validated['room_type_ids'] ?? []);

            foreach (Localization::locales() as $locale) {
                $input = $validated['translations'][$locale] ?? [];
                $name = trim((string) ($input['name'] ?? ''));

                if ($name === '') {
                    $plan->translations()->where('locale', $locale)->delete();

                    continue;
                }

                $plan->translations()->updateOrCreate(['locale' => $locale], [
                    'name' => $name,
                    'description' => $input['description'] ?? null,
                    'policy_text' => $input['policy_text'] ?? null,
                ]);
            }
        });

        return redirect('/admin/rate-plans/'.$plan->id.'/edit')->with('saved', __('admin.saved'));
    }

    /**
     * @return Collection<int,RoomType>
     */
    protected function roomTypes(): Collection
    {
        return RoomType::query()->ordered()->with('translations')->get();
    }
}
