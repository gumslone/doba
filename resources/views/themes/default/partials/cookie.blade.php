{{--
    §14: a cookie banner ONLY if analytics are enabled. An install that sets
    no analytics ID sets no non-essential cookies, and showing a consent
    dialog for cookies you do not set trains guests to click through
    consent dialogs — the opposite of what the rule is for.

    Hidden until the script confirms no prior choice, so a returning guest
    never sees it flash.
--}}
@if ($hotel->get('analytics.id'))
    <div data-cookie-notice hidden
         style="position:fixed;left:20px;bottom:20px;z-index:80;max-width:420px;background:#fff;border:1px solid var(--line);box-shadow:var(--shadow-lg);border-radius:var(--radius);padding:22px">
        <h4>{{ __('common.cookies') }}</h4>
        <p style="font-size:.84rem;color:var(--ink-soft);margin-top:8px">{{ __('common.cookies_body') }}</p>

        <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
            <button type="button" class="btn btn--primary" data-consent="all">{{ __('common.cookies_all') }}</button>
            <button type="button" class="btn btn--ghost" data-consent="necessary">{{ __('common.cookies_necessary') }}</button>
        </div>
    </div>
@endif
