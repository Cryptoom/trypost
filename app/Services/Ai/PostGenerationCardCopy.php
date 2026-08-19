<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Every line the post-generation card renders, resolved server-side in the
 * language of the conversation rather than the language of the interface.
 *
 * The card is the only place in the app where those two can differ: the
 * assistant answers in whatever language the user is writing in
 * (`resources/views/prompts/conversation/assistant.blade.php`), so a card
 * translated by the client's own locale bindings would put two languages in
 * one thread. The lines therefore travel with the payload, and the card does
 * no translation lookup of its own.
 *
 * Lines carrying `:placeholders` are shipped as templates — the card fills
 * `:account`, `:max`, `:count` and the sentence's own parts client-side, from
 * values only it has.
 */
final class PostGenerationCardCopy
{
    /**
     * The `chat.post_generation` lines the card renders. The `result_*` lines
     * of that same group belong to `ChatPostGenerationResult`, which renders
     * `generate_post`'s payload, not this one.
     *
     * @var list<string>
     */
    private const KEYS = [
        'unavailable',
        'styles_unavailable',
        'format_question',
        'style_question',
        'topic_question',
        'topic_placeholder',
        'topic_too_long',
        'images_question',
        'images_none',
        'account_question',
        'posting_to',
        'brand_colors_label',
        'brand_colors_description',
        'change',
        'submit',
        'sent',
        'sentence',
        'sentence_with_brand',
        'sentence_images_none',
        'sentence_images_one',
        'sentence_images_other',
        'sentence_brand_on',
        'sentence_brand_off',
    ];

    /**
     * @return array<string, string>
     */
    public static function forLocale(string $locale): array
    {
        $copy = [];

        foreach (self::KEYS as $key) {
            $copy[$key] = trans("chat.post_generation.{$key}", [], $locale);
        }

        return $copy;
    }
}
