<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Allergen;
use App\Enums\Diet;
use App\Http\Controllers\Controller;
use App\Models\Dish;
use App\Models\MenuSection;
use App\Models\Venue;
use App\Models\VenueTranslation;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminVenueController extends Controller
{
    public function index(): View
    {
        return view('admin.venues.index', [
            'venues' => Venue::query()
                ->with('translations')
                ->withCount('sections')
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.venues.edit', ['venue' => new Venue]);
    }

    public function edit(Venue $venue): View
    {
        $venue->load(['translations', 'sections.translations', 'sections.dishes.translations']);

        return view('admin.venues.edit', [
            'venue' => $venue,
            'allergens' => Allergen::cases(),
            'diets' => Diet::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Venue);
    }

    public function update(Request $request, Venue $venue): RedirectResponse
    {
        return $this->save($request, $venue);
    }

    public function destroy(Venue $venue): RedirectResponse
    {
        $venue->delete();

        return redirect('/admin/venues')->with('saved', __('admin.deleted'));
    }

    /**
     * Add a section to the card.
     */
    public function storeSection(Request $request, Venue $venue): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $section = $venue->sections()->create([
            'code' => mb_strtoupper($validated['code']),
            'sort_order' => (int) $venue->sections()->max('sort_order') + 1,
        ]);

        // Named in the editing language only; the other tabs are filled in
        // on the section itself, like every other translated record.
        $section->translations()->create([
            'locale' => Localization::defaultLocale(),
            'name' => $validated['name'],
        ]);

        return back()->with('saved', __('admin.saved'));
    }

    public function destroySection(Venue $venue, MenuSection $section): RedirectResponse
    {
        abort_unless($section->venue_id === $venue->id, 404);

        $section->delete();

        return back()->with('saved', __('admin.deleted'));
    }

    /**
     * Save every dish on the card in one submit.
     *
     * A card is edited the way it is read — top to bottom, several lines
     * at a time — so one form covers all of them rather than making the
     * chef open twenty pages to change twenty prices.
     */
    public function saveDishes(Request $request, Venue $venue): RedirectResponse
    {
        $validated = $request->validate([
            'dishes' => ['nullable', 'array'],
            'dishes.*.name' => ['nullable', 'string', 'max:255'],
            'dishes.*.description' => ['nullable', 'string', 'max:2000'],
            // Nullable on purpose: "market price" is a real menu entry,
            // and forcing a number would print €0.00 for the day's catch.
            'dishes.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'dishes.*.unit' => ['nullable', 'string', 'max:32'],
            'dishes.*.allergens' => ['nullable', 'array'],
            'dishes.*.allergens.*' => [Rule::in(Allergen::values())],
            'dishes.*.diets' => ['nullable', 'array'],
            'dishes.*.diets.*' => [Rule::in(Diet::values())],
            'dishes.*.is_available' => ['nullable', 'boolean'],
            'dishes.*.is_signature' => ['nullable', 'boolean'],
            'dishes.*.section' => ['nullable', 'integer'],
            'dishes.*.delete' => ['nullable', 'boolean'],
        ]);

        $locale = Localization::defaultLocale();
        $sectionIds = $venue->sections()->pluck('id')->all();

        DB::transaction(function () use ($validated, $sectionIds, $locale): void {
            foreach ($validated['dishes'] ?? [] as $key => $input) {
                $dish = str_starts_with((string) $key, 'new')
                    ? null
                    : Dish::query()->whereIn('menu_section_id', $sectionIds)->find((int) $key);

                if ($dish !== null && ($input['delete'] ?? false)) {
                    $dish->delete();

                    continue;
                }

                $name = trim((string) ($input['name'] ?? ''));

                if ($name === '') {
                    continue;   // an empty row is how a chef skips the blank line
                }

                $section = (int) ($input['section'] ?? 0);

                if (! in_array($section, $sectionIds, true)) {
                    continue;   // a section id from another venue is not ours to write
                }

                $attributes = [
                    'menu_section_id' => $section,
                    // Entered in euros, stored in minor units like every
                    // other amount (§5).
                    'price' => ($input['price'] ?? null) === null || $input['price'] === ''
                        ? null
                        : (int) round((float) $input['price'] * 100),
                    'unit' => $input['unit'] ?? null,
                    'allergens' => $input['allergens'] ?? [],
                    'diets' => $input['diets'] ?? [],
                    'is_available' => (bool) ($input['is_available'] ?? false),
                    'is_signature' => (bool) ($input['is_signature'] ?? false),
                ];

                $dish = $dish === null
                    ? Dish::query()->create($attributes)
                    : tap($dish)->update($attributes);

                $dish->translations()->updateOrCreate(['locale' => $locale], [
                    'name' => $name,
                    'description' => $input['description'] ?? null,
                ]);
            }
        });

        return back()->with('saved', __('admin.saved'));
    }

    protected function save(Request $request, Venue $venue): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('venues')->ignore($venue)],
            'type' => ['required', Rule::in(Venue::TYPES)],
            'phone' => ['nullable', 'string', 'max:64'],
            'price_range' => ['nullable', 'string', 'max:8'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'reservations' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'hours' => ['nullable', 'array'],
            'hours.*' => ['nullable', 'array'],
            'hours.*.*.from' => ['nullable', 'date_format:H:i'],
            'hours.*.*.to' => ['nullable', 'date_format:H:i'],
            'translations' => ['required', 'array'],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'translations.*.tagline' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:5000'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:255'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:320'],
        ]);

        // Slugs resolved and clash-checked before anything persists, so a
        // collision in the fourth language cannot leave the first three
        // saved and the record half-translated.
        $payloads = [];

        foreach (Localization::locales() as $locale) {
            $input = $validated['translations'][$locale] ?? [];
            $name = trim((string) ($input['name'] ?? ''));

            if ($name === '') {
                // Clearing the name unpublishes that language: its URL,
                // hreflang entry and sitemap line disappear together.
                $payloads[$locale] = null;

                continue;
            }

            $slug = trim((string) ($input['slug'] ?? '')) ?: Str::slug($name);

            $clash = VenueTranslation::query()
                ->where('locale', $locale)
                ->where('slug', $slug)
                ->where('venue_id', '!=', $venue->id)
                ->exists();

            if ($clash || in_array($slug, Localization::RESERVED, true)) {
                return back()->withInput()->withErrors([
                    "translations.{$locale}.slug" => __('admin.slug_taken', ['slug' => $slug, 'locale' => $locale]),
                ]);
            }

            $payloads[$locale] = [
                'slug' => $slug,
                'name' => $name,
                'tagline' => $input['tagline'] ?? null,
                'description' => $input['description'] ?? null,
                'meta_title' => $input['meta_title'] ?? null,
                'meta_description' => $input['meta_description'] ?? null,
            ];
        }

        DB::transaction(function () use ($venue, $validated, $payloads): void {
            $venue->fill([
                'code' => mb_strtoupper($validated['code']),
                'type' => $validated['type'],
                'phone' => $validated['phone'] ?? null,
                'price_range' => $validated['price_range'] ?? null,
                'seats' => $validated['seats'] ?? null,
                'opening_hours' => $this->hours($validated['hours'] ?? []),
                'reservations' => (bool) ($validated['reservations'] ?? false),
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ])->save();

            foreach ($payloads as $locale => $payload) {
                if ($payload === null) {
                    $venue->translations()->where('locale', $locale)->delete();
                } else {
                    $venue->translations()->updateOrCreate(['locale' => $locale], $payload);
                }
            }
        });

        return redirect('/admin/venues/'.$venue->id.'/edit')->with('saved', __('admin.saved'));
    }

    /**
     * Turn the form's day → period rows into the stored shape, dropping
     * half-filled periods.
     *
     * A day with no periods is stored as an empty array rather than
     * omitted: "closed on Monday" is a fact the page prints, and a missing
     * key would be indistinguishable from a day nobody has set up yet.
     *
     * @param  array<string,array<int,array<string,string|null>>>  $input
     * @return array<string,array<int,array<int,string>>>
     */
    protected function hours(array $input): array
    {
        $hours = [];

        foreach (Venue::DAYS as $day) {
            $periods = [];

            foreach ($input[$day] ?? [] as $period) {
                $from = $period['from'] ?? null;
                $to = $period['to'] ?? null;

                if ($from !== null && $to !== null && $from !== '' && $to !== '') {
                    $periods[] = [$from, $to];
                }
            }

            $hours[$day] = $periods;
        }

        return $hours;
    }
}
