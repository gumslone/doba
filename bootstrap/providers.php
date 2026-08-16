<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\SeoServiceProvider;
use App\Providers\ThemeServiceProvider;

return [
    AppServiceProvider::class,
    ThemeServiceProvider::class,
    SeoServiceProvider::class,
];
