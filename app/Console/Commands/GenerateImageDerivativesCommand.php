<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\Media\DerivativeGenerator;
use Illuminate\Console\Command;

class GenerateImageDerivativesCommand extends Command
{
    protected $signature = 'doba:images
                            {--force : Regenerate derivatives that already exist}';

    protected $description = 'Generate WebP srcset derivatives and backfill image dimensions for all media';

    public function handle(DerivativeGenerator $generator): int
    {
        $written = 0;
        $rows = 0;

        Media::query()->chunkById(50, function ($chunk) use ($generator, &$written, &$rows): void {
            foreach ($chunk as $media) {
                $written += $generator->generate($media, (bool) $this->option('force'));
                $rows++;
            }
        });

        $this->info("Processed {$rows} media rows, wrote {$written} derivatives.");

        return self::SUCCESS;
    }
}
