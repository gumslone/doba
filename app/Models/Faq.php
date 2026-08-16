<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A question a guest would otherwise email or phone about — parking,
 * check-in times, pets, breakfast hours.
 *
 * FAQs are not routable (no slug); they render as a section on existing
 * pages and as FAQPage JSON-LD. Answering the question on the page the
 * guest is already reading is worth more than a separate FAQ page nobody
 * links to.
 *
 * @property int $id
 * @property bool $is_published
 */
class Faq extends Model
{
    use HasTranslations;

    protected string $translationModel = FaqTranslation::class;

    protected string $translationForeignKey = 'faq_id';

    protected $fillable = ['sort_order', 'is_published'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_published' => 'boolean',
    ];

    /**
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * The published FAQs that have a question in the current locale (with
     * prose fallback), as {question, answer} pairs — the exact shape
     * JsonLd::faqs() and the Blade section both consume.
     *
     * @return array<int,array{question:string,answer:string}>
     */
    public static function forCurrentLocale(): array
    {
        return static::query()
            ->published()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('translations')
            ->get()
            ->map(static fn (Faq $faq): ?array => ($question = $faq->t('question')) !== null && ($answer = $faq->t('answer')) !== null
                ? ['question' => $question, 'answer' => $answer]
                : null)
            ->filter()
            ->values()
            ->all();
    }
}
