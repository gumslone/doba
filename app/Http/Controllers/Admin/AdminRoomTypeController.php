<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\RoomType;
use App\Support\Html;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The categories the hotel sells (§12).
 *
 * Until now a room type was born in the install wizard and never edited
 * again — names, occupancy, rates and descriptions were whatever the
 * wizard's template said. This is where they change.
 *
 * A type is a room or an apartment (§5). Both sell the same way; an
 * apartment additionally tells the guest how many bedrooms and bathrooms
 * it has, may insist on a minimum stay, and carries a cleaning fee that
 * is charged once per stay rather than per night.
 *
 * Capacity still lives on the availability grid: `total_units` here is
 * what the website ADVERTISES as the category's size, and the grid's
 * allotment is what it SELLS. Creating a type runs availability:extend
 * so it is bookable the moment it exists, seeded from total_units.
 */
class AdminRoomTypeController extends Controller
{
    public function index(): View
    {
        return view('admin.room-types.index', [
            'roomTypes' => RoomType::query()->ordered()->with(['translations', 'media'])->withCount('rooms')->get(),
            'defaultLocale' => Localization::defaultLocale(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new RoomType(['is_active' => true, 'kind' => RoomType::ROOM, 'min_nights' => 1, 'base_occupancy' => 2, 'max_occupancy' => 2, 'max_adults' => 2, 'max_children' => 0, 'total_units' => 1]));
    }

    public function edit(RoomType $roomType): View
    {
        return $this->form($roomType->load(['translations', 'amenities']));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $roomType = RoomType::create($this->attributes($validated) + [
            'code' => $this->code($validated),
            'sort_order' => (int) RoomType::query()->max('sort_order') + 1,
        ]);

        $this->saveTranslations($roomType, $validated);
        $roomType->amenities()->sync($validated['amenities'] ?? []);

        // Bookable the moment it exists: the grid is seeded from
        // total_units through the same command the wizard runs.
        Artisan::call('availability:extend');

        return redirect('/admin/room-types/'.$roomType->id.'/edit')
            ->with('saved', __('admin.room_type_created'));
    }

    public function update(Request $request, RoomType $roomType): RedirectResponse
    {
        $validated = $this->validated($request, $roomType);

        $roomType->update($this->attributes($validated));
        $this->saveTranslations($roomType, $validated);
        $roomType->amenities()->sync($validated['amenities'] ?? []);

        return redirect('/admin/room-types/'.$roomType->id.'/edit')
            ->with('saved', __('admin.room_type_saved'));
    }

    protected function form(RoomType $roomType): View
    {
        return view('admin.room-types.form', [
            'roomType' => $roomType,
            'locales' => Localization::locales(),
            'defaultLocale' => Localization::defaultLocale(),
            'amenities' => Amenity::query()->with('translations')->orderBy('sort_order')->get(),
            'selectedAmenities' => $roomType->exists ? $roomType->amenities->pluck('id')->all() : [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function validated(Request $request, ?RoomType $roomType = null): array
    {
        $default = Localization::defaultLocale();

        return $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'kind' => ['nullable', Rule::in(RoomType::KINDS)],
            'base_occupancy' => ['required', 'integer', 'min:1', 'max:20'],
            'max_occupancy' => ['required', 'integer', 'min:1', 'max:20', 'gte:base_occupancy'],
            'max_adults' => ['required', 'integer', 'min:1', 'max:20'],
            'max_children' => ['required', 'integer', 'min:0', 'max:20'],
            'default_rate' => ['required', 'integer', 'min:0', 'max:100000000'],
            'extra_adult_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'extra_child_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'total_units' => ['required', 'integer', 'min:1', 'max:5000'],
            'size_sqm' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'bed_setup' => ['nullable', 'string', 'max:120'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'cleaning_fee' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'min_nights' => ['nullable', 'integer', 'min:1', 'max:60'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['integer', 'exists:amenities,id'],
            'translations' => ['required', 'array'],
            // The default language must have a name: it is what every
            // other language falls back to, and what the code is cut from.
            "translations.{$default}.name" => ['required', 'string', 'max:255'],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'translations.*.short_description' => ['nullable', 'string', 'max:500'],
            'translations.*.description' => ['nullable', 'string', 'max:20000'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:255'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:320'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    protected function attributes(array $validated): array
    {
        return [
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'kind' => (string) ($validated['kind'] ?? RoomType::ROOM),
            'base_occupancy' => (int) $validated['base_occupancy'],
            'max_occupancy' => (int) $validated['max_occupancy'],
            'max_adults' => (int) $validated['max_adults'],
            'max_children' => (int) $validated['max_children'],
            'default_rate' => (int) $validated['default_rate'],
            'extra_adult_price' => (int) ($validated['extra_adult_price'] ?? 0),
            'extra_child_price' => (int) ($validated['extra_child_price'] ?? 0),
            'total_units' => (int) $validated['total_units'],
            'size_sqm' => $validated['size_sqm'] ?? null,
            'bed_setup' => $validated['bed_setup'] ?? null,
            'bedrooms' => $validated['bedrooms'] ?? null,
            'bathrooms' => $validated['bathrooms'] ?? null,
            'cleaning_fee' => (int) ($validated['cleaning_fee'] ?? 0),
            'min_nights' => max(1, (int) ($validated['min_nights'] ?? 1)),
        ];
    }

    /**
     * One row per language that has a name; a language with no name is
     * simply not served (§4), which is the rule the whole site follows.
     *
     * @param  array<string,mixed>  $validated
     */
    protected function saveTranslations(RoomType $roomType, array $validated): void
    {
        foreach (Localization::locales() as $locale) {
            $input = $validated['translations'][$locale] ?? [];
            $name = trim((string) ($input['name'] ?? ''));

            if ($name === '') {
                $roomType->translations()->where('locale', $locale)->delete();

                continue;
            }

            $slug = trim((string) ($input['slug'] ?? '')) ?: Str::slug($name);

            $roomType->translations()->updateOrCreate(['locale' => $locale], [
                'name' => $name,
                'slug' => $slug,
                'short_description' => $input['short_description'] ?? null,
                // Sanitised on write like every other editor field (§14).
                'description' => Html::clean($input['description'] ?? null),
                'meta_title' => $input['meta_title'] ?? null,
                'meta_description' => $input['meta_description'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    protected function code(array $validated): string
    {
        $name = (string) $validated['translations'][Localization::defaultLocale()]['name'];
        $base = mb_substr(Str::upper(Str::slug($name, '_')), 0, 56) ?: 'ROOM';
        $code = $base;

        for ($i = 2; RoomType::query()->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }
}
