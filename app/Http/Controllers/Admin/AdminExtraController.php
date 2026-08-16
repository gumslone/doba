<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AppliesPer;
use App\Http\Controllers\Controller;
use App\Models\Extra;
use App\Models\RoomType;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminExtraController extends Controller
{
    public function index(): View
    {
        return view('admin.extras.index', [
            'extras' => Extra::query()->orderBy('sort_order')->orderBy('id')->with('translations')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.extras.edit', [
            'extra' => new Extra(['applies_per' => AppliesPer::Stay, 'max_quantity' => 1]),
            'roomTypes' => $this->roomTypes(),
        ]);
    }

    public function edit(Extra $extra): View
    {
        $extra->load(['translations', 'roomTypes']);

        return view('admin.extras.edit', [
            'extra' => $extra,
            'roomTypes' => $this->roomTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Extra);
    }

    public function update(Request $request, Extra $extra): RedirectResponse
    {
        return $this->save($request, $extra);
    }

    public function destroy(Extra $extra): RedirectResponse
    {
        // Booked extras keep pointing at this row for the invoice, so an
        // extra that has ever been sold is deactivated rather than deleted.
        if ($extra->bookingExtras()->exists()) {
            $extra->update(['is_active' => false]);

            return redirect('/admin/extras')->with('saved', __('admin.extra_deactivated'));
        }

        $extra->delete();

        return redirect('/admin/extras')->with('saved', __('admin.deleted'));
    }

    protected function save(Request $request, Extra $extra): RedirectResponse
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:64', 'alpha_dash',
                Rule::unique('extras', 'code')->ignore($extra->id),
            ],
            // Entered in major units by a human; stored in minor units (§5).
            'price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'applies_per' => ['required', Rule::enum(AppliesPer::class)],
            'tax_rate' => ['required', 'integer', 'min:0', 'max:10000'],
            'max_quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'is_included' => ['sometimes', 'boolean'],
            'room_type_ids' => ['nullable', 'array'],
            'room_type_ids.*' => ['integer', 'exists:room_types,id'],
            'translations' => ['required', 'array'],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($extra, $validated): void {
            $extra->fill([
                'code' => mb_strtoupper($validated['code']),
                'price' => (int) round(((float) $validated['price']) * 100),
                'applies_per' => $validated['applies_per'],
                'tax_rate' => (int) $validated['tax_rate'],
                'max_quantity' => (int) $validated['max_quantity'],
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
                'is_active' => (bool) ($validated['is_active'] ?? false),
                'is_included' => (bool) ($validated['is_included'] ?? false),
            ])->save();

            // Empty selection = offered with every room (see Extra::scopeForRoomType).
            $extra->roomTypes()->sync($validated['room_type_ids'] ?? []);

            foreach (Localization::locales() as $locale) {
                $input = $validated['translations'][$locale] ?? [];
                $name = trim((string) ($input['name'] ?? ''));

                if ($name === '') {
                    $extra->translations()->where('locale', $locale)->delete();

                    continue;
                }

                $extra->translations()->updateOrCreate(['locale' => $locale], [
                    'name' => $name,
                    'description' => $input['description'] ?? null,
                ]);
            }
        });

        return redirect('/admin/extras/'.$extra->id.'/edit')->with('saved', __('admin.saved'));
    }

    /**
     * @return Collection<int,RoomType>
     */
    protected function roomTypes(): Collection
    {
        return RoomType::query()->ordered()->with('translations')->get();
    }
}
