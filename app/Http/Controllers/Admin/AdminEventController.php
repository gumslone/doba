<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTranslation;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminEventController extends Controller
{
    public function index(): View
    {
        return view('admin.events.index', [
            'events' => Event::query()->with('translations')->orderByDesc('starts_at')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.events.edit', ['event' => new Event]);
    }

    public function edit(Event $event): View
    {
        $event->load('translations');

        return view('admin.events.edit', ['event' => $event]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Event);
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        return $this->save($request, $event);
    }

    public function destroy(Event $event): RedirectResponse
    {
        $event->delete();

        return redirect('/admin/events')->with('saved', __('admin.deleted'));
    }

    protected function save(Request $request, Event $event): RedirectResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_published' => ['sometimes', 'boolean'],
            'translations' => ['required', 'array'],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'translations.*.excerpt' => ['nullable', 'string', 'max:1000'],
            'translations.*.body' => ['nullable', 'string', 'max:200000'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:255'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:320'],
        ]);

        // Same rule as pages: every slug is resolved and clash-checked
        // before anything persists.
        $payloads = [];

        foreach (Localization::locales() as $locale) {
            $input = $validated['translations'][$locale] ?? [];
            $title = trim((string) ($input['title'] ?? ''));

            if ($title === '') {
                $payloads[$locale] = null;

                continue;
            }

            $slug = trim((string) ($input['slug'] ?? '')) ?: Str::slug($title);

            $clash = EventTranslation::query()
                ->where('locale', $locale)
                ->where('slug', $slug)
                ->where('event_id', '!=', $event->id)
                ->exists();

            if ($clash || in_array($slug, Localization::RESERVED, true)) {
                return back()->withInput()->withErrors([
                    "translations.{$locale}.slug" => __('admin.slug_taken', ['slug' => $slug, 'locale' => $locale]),
                ]);
            }

            $payloads[$locale] = [
                'slug' => $slug,
                'title' => $title,
                'excerpt' => $input['excerpt'] ?? null,
                'body' => $input['body'] ?? null,
                'meta_title' => $input['meta_title'] ?? null,
                'meta_description' => $input['meta_description'] ?? null,
            ];
        }

        DB::transaction(function () use ($event, $validated, $payloads): void {
            $event->fill([
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'] ?? null,
                'location' => $validated['location'] ?? null,
                'is_published' => (bool) ($validated['is_published'] ?? false),
            ])->save();

            foreach ($payloads as $locale => $payload) {
                if ($payload === null) {
                    $event->translations()->where('locale', $locale)->delete();
                } else {
                    $event->translations()->updateOrCreate(['locale' => $locale], $payload);
                }
            }
        });

        return redirect('/admin/events/'.$event->id.'/edit')->with('saved', __('admin.saved'));
    }
}
