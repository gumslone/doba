<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

/**
 * The per-request SEO bag.
 *
 * One instance is bound as a singleton and shared with every view, so a
 * controller sets what it knows and the layout renders whatever is there.
 * Nothing here reaches into the database: page-level values come from the
 * *_translations tables via the controller, site-level defaults from the
 * settings table via HotelSettings.
 *
 * @implements Arrayable<string, mixed>
 */
class Seo implements Arrayable
{
    protected ?string $title = null;

    protected ?string $description = null;

    protected ?string $canonical = null;

    /** @var array<string,string> locale => absolute URL */
    protected array $alternates = [];

    protected ?string $image = null;

    protected string $type = 'website';

    protected bool $noindex = false;

    /** @var array<int,array<string,mixed>> */
    protected array $schemas = [];

    /** @var array<int,array{name:string,url:string|null}> */
    protected array $breadcrumbs = [];

    public function __construct(protected string $siteName) {}

    /**
     * Empty the bag for a new request.
     *
     * The container hands out one instance per request, which is enough
     * under FPM. It is not enough anywhere the container outlives a
     * request — an Octane worker, a test that makes two calls — and a bag
     * that survives publishes page A's Restaurant schema and breadcrumbs
     * on page B. Wrong structured data is worse than none: it is what a
     * search engine indexes.
     */
    public function reset(): static
    {
        $this->title = null;
        $this->description = null;
        $this->canonical = null;
        $this->alternates = [];
        $this->image = null;
        $this->type = 'website';
        $this->noindex = false;
        $this->schemas = [];
        $this->breadcrumbs = [];

        return $this;
    }

    public function title(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function description(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function canonical(?string $url): static
    {
        $this->canonical = $url;

        return $this;
    }

    /**
     * @param  array<string,string>  $alternates  locale => absolute URL
     */
    public function alternates(array $alternates): static
    {
        $this->alternates = $alternates;

        return $this;
    }

    public function image(?string $url): static
    {
        $this->image = $url;

        return $this;
    }

    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function noindex(bool $noindex = true): static
    {
        $this->noindex = $noindex;

        return $this;
    }

    /**
     * @param  array<string,mixed>  $schema
     */
    public function schema(array $schema): static
    {
        $this->schemas[] = $schema;

        return $this;
    }

    public function breadcrumb(string $name, ?string $url = null): static
    {
        $this->breadcrumbs[] = ['name' => $name, 'url' => $url];

        return $this;
    }

    /**
     * The full <title>. The site name is appended unless the page title
     * already contains it, which is what stops "Hotel Alpenhof · Hotel
     * Alpenhof" on the home page.
     */
    public function renderTitle(): string
    {
        $separator = (string) config('doba.seo.title_separator', ' · ');

        $title = $this->title !== null && $this->title !== ''
            ? (Str::contains($this->title, $this->siteName)
                ? $this->title
                : $this->title.$separator.$this->siteName)
            : $this->siteName;

        return static::clamp($title, (int) config('doba.seo.title_max', 60));
    }

    public function renderDescription(): ?string
    {
        if ($this->description === null || $this->description === '') {
            return null;
        }

        return static::clamp(
            trim(preg_replace('/\s+/u', ' ', strip_tags($this->description)) ?? ''),
            (int) config('doba.seo.description_max', 160)
        );
    }

    public function isNoindex(): bool
    {
        return $this->noindex || (bool) config('doba.seo.noindex', false);
    }

    public function getCanonical(): string
    {
        return $this->canonical ?? url()->current();
    }

    /**
     * @return array<string,string>
     */
    public function getAlternates(): array
    {
        return $this->alternates;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSiteName(): string
    {
        return $this->siteName;
    }

    /**
     * Every JSON-LD graph node for this page, including the breadcrumb trail
     * assembled from breadcrumb() calls.
     *
     * @return array<int,array<string,mixed>>
     */
    public function schemas(): array
    {
        $schemas = $this->schemas;

        if (count($this->breadcrumbs) > 1) {
            $schemas[] = JsonLd::breadcrumbs($this->breadcrumbs);
        }

        return $schemas;
    }

    /**
     * @return array<int,array{name:string,url:string|null}>
     */
    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    /**
     * Truncate on a word boundary — a title cut mid-word looks broken in the
     * SERP and reads as machine output.
     */
    protected static function clamp(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max - 1), " \t\n\r\0\x0B.,;:–-").'…';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->renderTitle(),
            'description' => $this->renderDescription(),
            'canonical' => $this->getCanonical(),
            'alternates' => $this->alternates,
            'image' => $this->image,
            'type' => $this->type,
            'noindex' => $this->isNoindex(),
            'schemas' => $this->schemas(),
        ];
    }
}
