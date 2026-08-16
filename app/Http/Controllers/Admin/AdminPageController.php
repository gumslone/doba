<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminPageController extends Controller
{
    public function index(): View
    {
        return view('admin.pages.index', [
            'pages' => Page::query()->with('translations')->orderBy('sort_order')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.pages.edit', ['page' => new Page]);
    }

    public function edit(Page $page): View
    {
        $page->load('translations');

        return view('admin.pages.edit', ['page' => $page]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Page);
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        return $this->save($request, $page);
    }

    public function destroy(Page $page): RedirectResponse
    {
        $page->delete();

        return redirect('/admin/pages')->with('saved', __('admin.deleted'));
    }

    protected function save(Request $request, Page $page): RedirectResponse
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:64', 'alpha_dash',
                Rule::unique('pages', 'code')->ignore($page->id),
            ],
            'is_published' => ['sometimes', 'boolean'],
            'show_in_menu' => ['sometimes', 'boolean'],
            'noindex' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'translations' => ['required', 'array'],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'translations.*.body' => ['nullable', 'string', 'max:200000'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:255'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:320'],
        ]);

        // Resolve and clash-check every slug BEFORE anything persists —
        // saving the parent first and bailing on a bad slug would leave an
        // orphan page behind the redirect.
        $payloads = [];

        foreach (Localization::locales() as $locale) {
            $input = $validated['translations'][$locale] ?? [];
            $title = trim((string) ($input['title'] ?? ''));

            if ($title === '') {
                $payloads[$locale] = null; // emptied title unpublishes this language

                continue;
            }

            $slug = trim((string) ($input['slug'] ?? '')) ?: Str::slug($title);

            $clash = PageTranslation::query()
                ->where('locale', $locale)
                ->where('slug', $slug)
                ->where('page_id', '!=', $page->id)
                ->exists();

            if ($clash || in_array($slug, Localization::RESERVED, true)) {
                return back()->withInput()->withErrors([
                    "translations.{$locale}.slug" => __('admin.slug_taken', ['slug' => $slug, 'locale' => $locale]),
                ]);
            }

            $payloads[$locale] = [
                'slug' => $slug,
                'title' => $title,
                'body' => $input['body'] ?? null,
                'meta_title' => $input['meta_title'] ?? null,
                'meta_description' => $input['meta_description'] ?? null,
            ];
        }

        DB::transaction(function () use ($page, $validated, $payloads): void {
            $page->fill([
                'code' => $validated['code'],
                'is_published' => (bool) ($validated['is_published'] ?? false),
                'show_in_menu' => (bool) ($validated['show_in_menu'] ?? false),
                'noindex' => (bool) ($validated['noindex'] ?? false),
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
            ])->save();

            foreach ($payloads as $locale => $payload) {
                if ($payload === null) {
                    $page->translations()->where('locale', $locale)->delete();
                } else {
                    $page->translations()->updateOrCreate(['locale' => $locale], $payload);
                }
            }
        });

        return redirect('/admin/pages/'.$page->id.'/edit')->with('saved', __('admin.saved'));
    }
}
