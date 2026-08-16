<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use App\Support\Seo\Seo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PageController extends Controller
{
    public function show(string $slug, Seo $seo, HotelSettings $hotel): View
    {
        $locale = app()->getLocale();

        $page = Page::findBySlug($slug, $locale)
            ?? throw new NotFoundHttpException("No page [{$slug}] in [{$locale}].");

        $url = Localization::route('page', ['slug' => $slug]);
        $ogImage = $page->t('og_image');

        $seo->title($page->t('meta_title') ?: $page->t('title'))
            ->description($page->t('meta_description') ?: $page->t('body'))
            ->image($ogImage ? Storage::disk('public')->url($ogImage) : $hotel->ogImage())
            ->type('article')
            ->canonical($url)
            ->alternates(Localization::alternates(
                'page',
                static fn (string $l): ?array => ($s = $page->slug($l)) ? ['slug' => $s] : null
            ))
            ->noindex($page->noindex)
            ->breadcrumb($hotel->name, Localization::route('home'))
            ->breadcrumb((string) $page->t('title'), $url);

        $template = view()->exists("pages.{$page->template}")
            ? "pages.{$page->template}"
            : 'pages.default';

        return view($template, [
            'page' => $page,
        ]);
    }
}
