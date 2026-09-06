<?php

declare(strict_types=1);

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Stored HTML, made safe to print (§14).
 *
 * Page bodies, event texts and descriptions are written in the admin's
 * WYSIWYG editor and rendered unescaped, because they are HTML. Authors
 * are admins, so a guest cannot plant anything here — but a single
 * phished admin session could, and persistent script on the public site
 * is the one outcome the rest of the security headers exist to prevent.
 *
 * So every such field passes through here on WRITE, and the views pass
 * it through again on RENDER: the first keeps the database clean, the
 * second covers content that was stored before this class existed, and
 * neither trusts the other. The allow-list is what the editor can
 * produce and nothing more.
 */
final class Html
{
    /**
     * Part of every cache key. Bump it with any change to the purifier's
     * configuration below, or a body cleaned under the old rules is
     * served under the new ones until its cache entry happens to expire
     * — which is exactly how a tightened allow-list fails to tighten.
     */
    private const VERSION = 2;

    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        // Purifying is not free, and the same page body renders on every
        // request: remembered by content hash, so an unchanged text is
        // purified once per deploy rather than once per visitor.
        return Cache::remember(
            'html:'.self::VERSION.':'.hash('sha256', $html),
            86400,
            static fn (): string => self::purifier()->purify($html),
        );
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $cacheDir = storage_path('framework/cache/purifier');
        File::ensureDirectoryExists($cacheDir);

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cacheDir);
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        // What Trix emits, and the handful of things a hotelier pastes
        // from a Word document. No script, no style, no event handlers,
        // no forms, no iframes — an editor has no business producing them.
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
            'a[href|title|rel]', 'img[src|alt|width|height]',
            'div', 'span', 'figure', 'figcaption', 'hr',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
        ]));
        // http, https, mailto, tel and relative paths. Never javascript:,
        // never data: — a data: image is a script waiting for a parser bug.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        // A stripped <script> must not leave its source behind as prose:
        // "alert(1)" printed in a page body is harmless and still wrong.
        $config->set('Core.RemoveScriptContents', true);
        $config->set('AutoFormat.RemoveEmpty', false);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);

        // HTMLPurifier predates HTML5: figure and figcaption — which is
        // how Trix wraps an attached image — have to be taught to it, or
        // it refuses the allow-list rather than the element.
        // A raw definition needs an identity so its cache can be keyed;
        // bump the revision whenever the allow-list below changes.
        $config->set('HTML.DefinitionID', 'doba-editor');
        $config->set('HTML.DefinitionRev', self::VERSION);

        $definition = $config->maybeGetRawHTMLDefinition();

        if ($definition !== null) {
            $definition->addElement('figure', 'Block', 'Flow', 'Common');
            $definition->addElement('figcaption', 'Inline', 'Flow', 'Common');
        }

        return self::$purifier = new HTMLPurifier($config);
    }
}
