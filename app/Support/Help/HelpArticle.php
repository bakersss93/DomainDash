<?php

namespace App\Support\Help;

/** One knowledge-base article (metadata plus the raw Markdown body). Immutable. */
final class HelpArticle
{
    public const AUDIENCE_CLIENT = 'client';
    public const AUDIENCE_INTEGRATION = 'integration';

    /**
     * @param list<string> $related slugs of related articles (optional front matter)
     */
    public function __construct(
        public readonly string $audience,
        public readonly string $category,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly int $order,
        public readonly ?string $permission,
        public readonly array $related,
        public readonly string $body,
    ) {
    }

    public function isIntegration(): bool
    {
        return $this->audience === self::AUDIENCE_INTEGRATION;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) $a['audience'], (string) $a['category'], (string) $a['slug'], (string) $a['title'], (string) $a['summary'],
            (int) $a['order'], $a['permission'] !== null ? (string) $a['permission'] : null, array_values((array) $a['related']), (string) $a['body'],
        );
    }
}
