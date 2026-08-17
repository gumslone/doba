<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * resources/api/openapi.yaml -> public/openapi.json.
 *
 * The YAML is what a person edits; the JSON is what a partner's tooling
 * fetches. Committing the generated copy means serving it costs nothing
 * at runtime and needs no YAML parser in production — and a test fails if
 * the two ever drift, so the committed copy cannot go stale.
 */
class GenerateOpenApi extends Command
{
    protected $signature = 'doba:openapi {--check : Fail instead of writing when the committed copy is stale}';

    protected $description = 'Generate public/openapi.json from resources/api/openapi.yaml';

    public const SOURCE = 'resources/api/openapi.yaml';

    public const TARGET = 'public/openapi.json';

    public function handle(): int
    {
        $json = self::render();
        $target = base_path(self::TARGET);

        if ($this->option('check')) {
            if (is_file($target) && file_get_contents($target) === $json) {
                $this->info('public/openapi.json is up to date.');

                return self::SUCCESS;
            }

            $this->error('public/openapi.json is stale. Run: php artisan doba:openapi');

            return self::FAILURE;
        }

        file_put_contents($target, $json);
        $this->info('Wrote '.self::TARGET.'.');

        return self::SUCCESS;
    }

    /**
     * Pretty-printed and with a trailing newline, so the committed file
     * reviews as a readable diff rather than one very long line.
     */
    public static function render(): string
    {
        /** @var array<string,mixed> $spec */
        $spec = Yaml::parseFile(base_path(self::SOURCE));

        return json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
