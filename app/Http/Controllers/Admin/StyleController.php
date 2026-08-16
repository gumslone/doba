<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Hotel\HotelSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Customisable styles, the §3 way: colours, fonts and a custom-CSS escape
 * hatch are SETTINGS emitted as CSS variables — never a theme file and
 * never a code fork. A theme override directory (DOBA_THEME) is for
 * structural layout changes only.
 */
class StyleController extends Controller
{
    /**
     * Font stacks are a curated list rather than free text: every option
     * is system-native, so no webfont request ever enters the critical
     * path and the Core Web Vitals budget (§11) survives the hotelier's
     * taste in typography.
     */
    public const FONT_STACKS = [
        'sans' => "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
        'serif' => "ui-serif, Georgia, Cambria, 'Times New Roman', serif",
        'humanist' => "Seravek, 'Gill Sans Nova', Ubuntu, Calibri, 'DejaVu Sans', source-sans-pro, sans-serif",
        'geometric' => "Avenir, Montserrat, Corbel, 'URW Gothic', source-sans-pro, sans-serif",
    ];

    public function edit(HotelSettings $hotel): View
    {
        return view('admin.styles.edit', [
            'fontStacks' => self::FONT_STACKS,
            'current' => [
                'color_primary' => $hotel->get('branding.color_primary', '#1f2937'),
                'color_accent' => $hotel->get('branding.color_accent', '#0f766e'),
                'font_heading' => $hotel->get('branding.font_heading', 'sans'),
                'font_body' => $hotel->get('branding.font_body', 'sans'),
                'custom_css' => $hotel->get('branding.custom_css', ''),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'color_primary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_heading' => ['required', 'in:'.implode(',', array_keys(self::FONT_STACKS))],
            'font_body' => ['required', 'in:'.implode(',', array_keys(self::FONT_STACKS))],
            'custom_css' => ['nullable', 'string', 'max:20000'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::put('branding', $key, $value);
        }

        return redirect('/admin/styles')->with('saved', __('admin.saved'));
    }
}
