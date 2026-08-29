<?php

/**
 * Doba web installer — one file, uploaded by FTP, opened in a browser.
 *
 * For the hotelier whose only tools are a browser and an FTP client: it
 * checks the server, downloads the newest Doba release from GitHub,
 * verifies its checksum, extracts it around itself, writes a bootable
 * .env, deletes itself, and hands over to the install wizard at /install
 * — where every actual question (database, hotel, rooms, account) is
 * asked exactly once, by the same code an operator with a shell uses.
 *
 * Deliberately written in PHP-7-compatible syntax: on a host still
 * running old PHP this file must PARSE, so it can say "this needs PHP
 * 8.4" in a sentence — a parse error tells the hotelier nothing.
 *
 * Security model, same as the wizard's: on first load a token file is
 * written next to this script, and every action requires its contents.
 * Whoever can read a file on the server is exactly the person entitled
 * to install onto it. A passerby who merely found the URL cannot.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

if (PHP_VERSION_ID < 70400) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "This server runs PHP " . PHP_VERSION . ".\n";
    echo "Doba needs PHP 8.4 or newer. Ask your hosting provider to switch\n";
    echo "the PHP version for this site, then reload this page.\n";
    exit;
}

class DobaWebInstaller
{
    const REPO = 'gumslone/doba';
    const TOKEN_FILE = 'doba-installer-token.txt';

    /** @var string */
    private $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir !== null ? $dir : __DIR__;
    }

    // ------------------------------------------------------------ checks

    /**
     * Only what stops the DOWNLOAD-AND-EXTRACT from working. The full
     * server check (every extension, writability of each directory,
     * with each failure carrying its fix) belongs to the wizard, which
     * runs it as a blocking step. Duplicating that list here means the
     * two lists disagree within a year.
     *
     * @return array<int, array{ok: bool, label: string, detail: string}>
     */
    public function requirements(): array
    {
        $canFetch = function_exists('curl_init') || ini_get('allow_url_fopen');

        return array(
            array(
                'ok' => PHP_VERSION_ID >= 80400,
                'label' => 'PHP 8.4 or newer',
                'detail' => PHP_VERSION_ID >= 80400
                    ? 'PHP ' . PHP_VERSION
                    : 'This server runs PHP ' . PHP_VERSION . '. Ask your host to switch this site to PHP 8.4.',
            ),
            array(
                'ok' => $canFetch,
                'label' => 'Can download files',
                'detail' => $canFetch
                    ? (function_exists('curl_init') ? 'via curl' : 'via allow_url_fopen')
                    : 'Neither the curl extension nor allow_url_fopen is available, so nothing can be downloaded. Ask your host to enable one.',
            ),
            array(
                'ok' => class_exists('PharData') && function_exists('gzopen'),
                'label' => 'Can extract archives',
                'detail' => class_exists('PharData') && function_exists('gzopen')
                    ? 'phar and zlib'
                    : 'The phar or zlib extension is missing, so the release cannot be unpacked.',
            ),
            array(
                'ok' => is_writable($this->dir),
                'label' => 'This directory is writable',
                'detail' => is_writable($this->dir)
                    ? $this->dir
                    : $this->dir . ' is not writable by PHP, so nothing can be installed into it.',
            ),
        );
    }

    /** @param array<int, array{ok: bool}> $requirements */
    public function requirementsMet(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if (!$requirement['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Doba's files already here? Then this script's job is done and the
     * wizard's has begun — point there instead of downloading on top.
     */
    public function alreadyExtracted(): bool
    {
        return is_file($this->dir . '/artisan');
    }

    // ------------------------------------------------------------ token

    public function tokenPath(): string
    {
        return $this->dir . '/' . self::TOKEN_FILE;
    }

    /**
     * Written on first load, so the page is never even briefly open to
     * someone who cannot read the server's files.
     */
    public function ensureToken(): string
    {
        if (!is_file($this->tokenPath())) {
            file_put_contents($this->tokenPath(), bin2hex(random_bytes(20)));
            @chmod($this->tokenPath(), 0600);
        }

        return trim((string) file_get_contents($this->tokenPath()));
    }

    public function tokenValid(string $given): bool
    {
        return is_file($this->tokenPath())
            && $given !== ''
            && hash_equals($this->ensureToken(), trim($given));
    }

    // ------------------------------------------------------------ release

    /**
     * The newest release out of the releases LIST — never the
     * /releases/latest endpoint, which only ever returns full releases.
     * Every 0.x Doba release is marked pre-release on purpose, so asking
     * "latest" would 404 until v1.0.0 and this installer would tell
     * every early hotelier there is nothing to install.
     *
     * @param array<int, mixed> $releases as GitHub's API returns them
     * @return array{name: string, tarball: string, checksum: string}|null
     */
    public function pickRelease(array $releases)
    {
        foreach ($releases as $release) {
            $tarball = null;
            $checksum = null;

            $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : array();

            foreach ($assets as $asset) {
                $url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

                if (substr($url, -7) === '.tar.gz') {
                    $tarball = $url;
                } elseif (substr($url, -7) === '.sha256') {
                    $checksum = $url;
                }
            }

            if ($tarball !== null && $checksum !== null) {
                return array(
                    'name' => isset($release['tag_name']) ? (string) $release['tag_name'] : 'unknown',
                    'tarball' => $tarball,
                    'checksum' => $checksum,
                );
            }
        }

        return null;
    }

    /**
     * Overridable so the release workflow can point this very file at
     * the tarball it just built and prove the two install together —
     * the web installer is the artefact nobody exercises in development,
     * which is exactly how it would rot.
     */
    public function releasesUrl(): string
    {
        $override = getenv('DOBA_RELEASES_URL');

        return is_string($override) && $override !== ''
            ? $override
            : 'https://api.github.com/repos/' . self::REPO . '/releases?per_page=5';
    }

    /** @return array<int, mixed> */
    public function fetchReleases(): array
    {
        $json = $this->fetch($this->releasesUrl());
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * curl when the extension exists, streams otherwise; GitHub requires
     * a User-Agent either way.
     */
    public function fetch(string $url, ?string $saveTo = null): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            $file = $saveTo !== null ? fopen($saveTo, 'wb') : null;

            curl_setopt_array($curl, array(
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_USERAGENT => 'doba-installer',
                CURLOPT_FAILONERROR => true,
            ));

            if ($file !== null) {
                curl_setopt($curl, CURLOPT_FILE, $file);
            } else {
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            }

            $body = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);

            if ($file !== null) {
                fclose($file);
            }

            if ($error !== '') {
                throw new RuntimeException('Download failed: ' . $error);
            }

            return is_string($body) ? $body : '';
        }

        $context = stream_context_create(array(
            'http' => array('header' => "User-Agent: doba-installer\r\n", 'timeout' => 300, 'follow_location' => 1),
        ));

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException('Download failed: ' . $url);
        }

        if ($saveTo !== null) {
            file_put_contents($saveTo, $body);

            return '';
        }

        return $body;
    }

    // ------------------------------------------------------------ verify + extract

    public function checksumMatches(string $file, string $checksumLine): bool
    {
        $expected = strtolower(strtok(trim($checksumLine), " \t"));

        return $expected !== false
            && preg_match('/^[0-9a-f]{64}$/', $expected) === 1
            && hash_equals($expected, hash_file('sha256', $file));
    }

    /**
     * Unpack the tarball and move the app up around this script.
     */
    public function extract(string $tarball): void
    {
        $tar = preg_replace('/\.gz$/', '', $tarball);

        if (is_file($tar)) {
            unlink($tar);
        }

        $archive = new PharData($tarball);
        $archive->decompress();

        $unpacked = new PharData($tar);
        $unpacked->extractTo($this->dir, null, true);

        unlink($tar);

        if (!is_dir($this->dir . '/doba')) {
            throw new RuntimeException('The tarball did not contain a doba/ directory.');
        }

        foreach (scandir($this->dir . '/doba') ?: array() as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            rename($this->dir . '/doba/' . $entry, $this->dir . '/' . $entry);
        }

        rmdir($this->dir . '/doba');
    }

    // ------------------------------------------------------------ .env

    /**
     * The smallest .env that boots: production mode, a key, the URL.
     * Everything else is the wizard's to ask.
     */
    public function writeEnv(string $url): void
    {
        $source = is_file($this->dir . '/env.example')
            ? $this->dir . '/env.example'
            : $this->dir . '/.env.example';

        $env = (string) file_get_contents($source);

        $set = function (string $key, string $value) use (&$env): void {
            $env = preg_match('/^' . $key . '=.*$/m', $env)
                ? (string) preg_replace('/^' . $key . '=.*$/m', $key . '=' . $value, $env)
                : $env . "\n" . $key . '=' . $value . "\n";
        };

        // Not the example's development defaults: a debug page prints
        // configuration to whoever causes an error.
        $set('APP_ENV', 'production');
        $set('APP_DEBUG', 'false');
        $set('APP_KEY', 'base64:' . base64_encode(random_bytes(32)));
        $set('APP_URL', rtrim($url, '/'));

        file_put_contents($this->dir . '/.env', $env);
    }

    // ------------------------------------------------------------ docroot fallback

    /**
     * For a host where the document root cannot be pointed at public/.
     *
     * Second best, and said so: everything is rewritten into public/,
     * and the paths that must never be served are denied OUTRIGHT as
     * well — so if mod_rewrite is off, the deny rules still stand
     * between the internet and .env. Apache only; on nginx the docroot
     * change is the only safe answer, and the page says that too.
     */
    public function guardHtaccess(): string
    {
        return <<<'HTACCESS'
# Written by the Doba installer for hosts where the document root cannot
# be pointed at public/. If you CAN point it at public/, do that instead
# and delete this file.

<IfModule mod_authz_core.c>
    <FilesMatch "^(\.env.*|composer\..*|artisan|VERSION)$">
        Require all denied
    </FilesMatch>
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Nothing outside public/ is ever served.
    RewriteRule ^(app|bootstrap|config|database|resources|routes|storage|vendor|lang|scripts)(/|$) - [F,L]

    RewriteCond %{REQUEST_URI} !^/?public/
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
HTACCESS;
    }

    // ------------------------------------------------------------ run

    public function install(string $url, bool $withHtaccess): string
    {
        set_time_limit(0);

        $release = $this->pickRelease($this->fetchReleases());

        if ($release === null) {
            throw new RuntimeException('No release with a tarball and checksum was found at github.com/' . self::REPO . '/releases.');
        }

        $tarball = $this->dir . '/doba-release.tar.gz';

        $this->fetch($release['tarball'], $tarball);
        $checksum = $this->fetch($release['checksum']);

        if (!$this->checksumMatches($tarball, $checksum)) {
            unlink($tarball);
            throw new RuntimeException('Checksum mismatch — the download is corrupt or tampered with. Nothing was installed.');
        }

        $this->extract($tarball);
        unlink($tarball);

        $this->writeEnv($url);

        if ($withHtaccess) {
            file_put_contents($this->dir . '/.htaccess', $this->guardHtaccess());
        }

        // This script has no second use. The token dies with it.
        @unlink($this->tokenPath());
        @unlink(__FILE__);

        return $release['name'];
    }

    public function guessUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

        return ($https ? 'https' : 'http') . '://' . $host . $path;
    }
}

// Run only when actually served — a test that includes this file, or a
// stray `php doba-installer.php`, must not download anything.
if (PHP_SAPI === 'cli') {
    return;
}

$installer = new DobaWebInstaller();

$page = function (string $title, string $body): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{font:16px/1.6 system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem;color:#1a1a1a}'
        . 'h1{font-size:1.4rem}code{background:#f3f3f3;padding:.1rem .35rem;border-radius:3px;font-size:.9em}'
        . '.ok{color:#166534}.fail{color:#b91c1c}.note{background:#fffbeb;border:1px solid #fde68a;padding:.75rem 1rem;border-radius:6px}'
        . 'ul{padding-left:1.2rem}li{margin:.4rem 0}input[type=text],input[type=url]{width:100%;padding:.5rem;border:1px solid #ccc;border-radius:4px;font:inherit}'
        . 'button{background:#1a1a1a;color:#fff;border:0;padding:.65rem 1.4rem;border-radius:6px;font:inherit;cursor:pointer}'
        . 'label{display:block;margin:1rem 0 .25rem;font-weight:600}.hint{color:#555;font-size:.9em}</style>'
        . '</head><body><h1>' . htmlspecialchars($title) . '</h1>' . $body . '</body></html>';
};

if ($installer->alreadyExtracted()) {
    $page('Doba is already here', '<p>The files are already in place. Configuration happens in the '
        . '<a href="install">install wizard</a> — this page has nothing left to do. '
        . 'You can delete <code>' . htmlspecialchars(basename(__FILE__)) . '</code>.</p>');

    return;
}

$requirements = $installer->requirements();
$installer->ensureToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$installer->tokenValid(isset($_POST['token']) ? (string) $_POST['token'] : '')) {
        http_response_code(403);
        $page('Wrong token', '<p class="fail">That is not what <code>' . htmlspecialchars($installer->tokenPath())
            . '</code> contains. Open that file on the server and paste its exact contents.</p>'
            . '<p><a href="">Back</a></p>');

        return;
    }

    if (!$installer->requirementsMet($requirements)) {
        http_response_code(422);
        $page('Not yet', '<p class="fail">The server check below has to pass first.</p>');

        return;
    }

    try {
        $version = $installer->install(
            isset($_POST['url']) && trim((string) $_POST['url']) !== '' ? (string) $_POST['url'] : $installer->guessUrl(),
            !empty($_POST['htaccess'])
        );
    } catch (Throwable $e) {
        http_response_code(500);
        $page('That did not work', '<p class="fail">' . htmlspecialchars($e->getMessage()) . '</p>'
            . '<p>Nothing that was downloaded has been kept. Fix the cause and <a href="">try again</a>.</p>');

        return;
    }

    $page('Doba ' . $version . ' is extracted', '<p>One step left: '
        . (empty($_POST['htaccess'])
            ? 'point your web server\'s document root at the <code>public/</code> directory next to this page, then '
            : '')
        . 'open <a href="install"><strong>the install wizard</strong></a>, which asks everything else '
        . '(database, hotel, rooms, your account) and checks the server properly as its first step.</p>'
        . (is_file(__FILE__)
            ? '<p class="note">This installer could not delete itself — remove <code>'
                . htmlspecialchars(basename(__FILE__)) . '</code> by FTP now.</p>'
            : ''));

    return;
}

$list = '';

foreach ($requirements as $requirement) {
    $list .= '<li class="' . ($requirement['ok'] ? 'ok' : 'fail') . '">'
        . ($requirement['ok'] ? '&#10003; ' : '&#10007; ')
        . '<strong>' . htmlspecialchars($requirement['label']) . '</strong> — '
        . htmlspecialchars($requirement['detail']) . '</li>';
}

$page('Install Doba', '<p>This downloads the newest Doba release from GitHub, verifies it, and unpacks it here. '
    . 'All configuration happens afterwards, in a wizard.</p>'
    . '<ul>' . $list . '</ul>'
    . '<form method="post">'
    . '<label for="token">Proof this server is yours</label>'
    . '<p class="hint">A file named <code>' . htmlspecialchars(DobaWebInstaller::TOKEN_FILE) . '</code> was just written '
    . 'next to this script. Open it (FTP, or your host\'s file manager) and paste its contents:</p>'
    . '<input type="text" id="token" name="token" required autocomplete="off">'
    . '<label for="url">Public address of the site</label>'
    . '<input type="url" id="url" name="url" value="' . htmlspecialchars($installer->guessUrl()) . '">'
    . '<label><input type="checkbox" name="htaccess" value="1"> My host cannot change the document root</label>'
    . '<p class="hint">Doba serves from its <code>public/</code> directory. Pointing your domain there is one setting in '
    . 'most hosting panels and is the right answer. Tick this only if yours truly cannot: an Apache '
    . '<code>.htaccess</code> is written instead that routes everything into <code>public/</code> and blocks the '
    . 'files that must never be served. On nginx there is no such fallback — the document root must change.</p>'
    . '<p style="margin-top:1.5rem"><button' . ($installer->requirementsMet($requirements) ? '' : ' disabled') . '>'
    . 'Download and install</button></p>'
    . '</form>');
