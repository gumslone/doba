import 'trix';
import Alpine from 'alpinejs';

// Server-rendered Blade is the default (§1) — Alpine handles the few pieces
// that must be interactive (the date picker, the gallery, the language menu)
// without a build step per hotel or a hydration cost on every page.
window.Alpine = Alpine;
Alpine.start();
