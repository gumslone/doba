<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\DiscountType;
use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\RoomType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class AdminPromoCodeController extends Controller
{
    public function index(): View
    {
        return view('admin.promo-codes.index', [
            'codes' => PromoCode::query()
                ->withCount(['redemptions as active_redemptions_count' => fn ($query) => $query->whereNull('released_at')])
                ->withSum(['redemptions as discount_given' => fn ($query) => $query->whereNull('released_at')], 'amount')
                ->orderByDesc('is_active')
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.promo-codes.edit', [
            'code' => new PromoCode(['discount_type' => DiscountType::Percent, 'value' => 1000]),
            'roomTypes' => $this->roomTypes(),
        ]);
    }

    public function edit(PromoCode $promoCode): View
    {
        return view('admin.promo-codes.edit', [
            'code' => $promoCode,
            'roomTypes' => $this->roomTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new PromoCode);
    }

    public function update(Request $request, PromoCode $promoCode): RedirectResponse
    {
        return $this->save($request, $promoCode);
    }

    public function destroy(PromoCode $promoCode): RedirectResponse
    {
        // A code that has ever been redeemed is deactivated, never deleted:
        // the bookings it discounted still point at it, and a campaign
        // report that loses its codes cannot answer what the campaign did.
        if ($promoCode->redemptions()->exists()) {
            $promoCode->forceFill(['is_active' => false])->save();

            return redirect('/admin/promo-codes')->with('status', __('admin.promo_deactivated'));
        }

        $promoCode->delete();

        return redirect('/admin/promo-codes')->with('status', __('admin.deleted'));
    }

    protected function save(Request $request, PromoCode $promoCode): RedirectResponse
    {
        // Normalised BEFORE validation, so the uniqueness rule compares the
        // value that will actually be stored — checking the raw input lets
        // "spring25" past a unique index that already holds "SPRING25".
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('promo_codes')->ignore($promoCode)],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'value' => ['required', 'integer', 'min:1'],
            'min_nights' => ['nullable', 'integer', 'min:1', 'max:255'],
            'min_total' => ['nullable', 'integer', 'min:0'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'stay_from' => ['nullable', 'date_format:Y-m-d'],
            'stay_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:stay_from'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_guest_limit' => ['nullable', 'integer', 'min:1'],
            'room_type_ids' => ['nullable', 'array'],
            'room_type_ids.*' => ['integer', 'exists:room_types,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // A percentage arrives from the form as a percentage and is stored
        // in basis points, so no float ever reaches the money path.
        if ($validated['discount_type'] === DiscountType::Percent->value) {
            $validated['value'] = min(10000, (int) $validated['value'] * 100);
        }

        $promoCode->fill($validated + [
            'is_active' => $request->boolean('is_active'),
            // An empty selection means every room type, not none — the
            // difference between a code that works and one that never can.
            'room_type_ids' => null,
        ])->save();

        return redirect('/admin/promo-codes')->with('status', __('admin.saved'));
    }

    /**
     * @return Collection<int,array{id:int,label:string}>
     */
    protected function roomTypes(): Collection
    {
        return RoomType::query()
            ->with('translations')
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (RoomType $type): array => [
                'id' => $type->id,
                'label' => $type->t('name') ?? $type->code,
            ]);
    }
}
