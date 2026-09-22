<?php

declare(strict_types=1);

/**
 * public/.htaccess is read by every Apache shared host and by the Docker
 * image, and by nothing in this test suite — artisan serve ignores it. A
 * directive that is not allowed in .htaccess context makes Apache answer
 * every request with a 500, which is how v0.4.0 nearly shipped broken.
 */
it('uses only directives Apache allows inside .htaccess', function (): void {
    $htaccess = (string) file_get_contents(public_path('.htaccess'));

    // Server- and vhost-context sections. Directory is not allowed either:
    // .htaccess IS the directory section.
    foreach (['<Location', '<LocationMatch', '<VirtualHost', '<Directory', '<DirectoryMatch', 'ServerName', 'DocumentRoot', 'Listen'] as $forbidden) {
        expect($htaccess)->not->toContain($forbidden, "{$forbidden} is not allowed in .htaccess; Apache would answer 500 to every request");
    }

    // Every section opened is closed, and every module-specific block is
    // guarded so a host without that module still serves the site.
    foreach (['IfModule', 'FilesMatch'] as $section) {
        expect(substr_count($htaccess, "<{$section}"))->toBe(substr_count($htaccess, "</{$section}>"), "{$section} sections are unbalanced");
    }

    expect($htaccess)->toContain('AddOutputFilterByType DEFLATE')
        ->and($htaccess)->toContain('immutable');
});
