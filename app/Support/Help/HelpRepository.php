<?php

namespace App\Support\Help;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Loads the Markdown knowledge base from disk, caches the index and answers every "may this user see it" question.
 *
 * Security properties:
 *  - Files are only ever found by scanning {path}/{audience}/{category}/{slug}.md. A request's category/slug is looked
 *    up in that index; it is never concatenated into a file path, so "../" cannot reach anything.
 *  - Names must match SLUG_PATTERN or the file is ignored.
 *  - Every list (index, category, search, related) and every single-article lookup goes through canView().
 */
class HelpRepository
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';
    private const PERMISSION_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';
    private const AUDIENCES = [HelpArticle::AUDIENCE_CLIENT, HelpArticle::AUDIENCE_INTEGRATION];

    /** @var array<string,HelpArticle>|null */
    private ?array $memo = null;

    public function __construct(private readonly string $path, private readonly string $integrationsPermission = 'help.integrations.view')
    {
    }

    public static function make(): self
    {
        return new self((string) config('help.path'), (string) config('help.integrations_permission', 'help.integrations.view'));
    }

    // ------------------------------------------------------------------
    // Access control
    // ------------------------------------------------------------------

    public function canView(?User $user, HelpArticle $article): bool
    {
        if ($user === null) {
            return false;
        }

        if ($article->isIntegration()) {
            // Integration set-up: staff only AND the dedicated permission (Administrators always).
            return $user->isStaff() && ($user->hasRole('Administrator') || $user->can($this->integrationsPermission));
        }

        if ($article->permission === null) {
            return true;
        }

        // Client how-to tagged with the permission of the feature it describes. Staff see everything.
        return $user->isStaff() || $user->can($article->permission);
    }

    // ------------------------------------------------------------------
    // Lookups (all filtered by canView)
    // ------------------------------------------------------------------

    /** @return list<HelpArticle> visible articles, ordered by category order then article order then title */
    public function visibleTo(?User $user): array
    {
        $articles = array_values(array_filter($this->all(), fn (HelpArticle $a) => $this->canView($user, $a)));
        usort($articles, fn (HelpArticle $a, HelpArticle $b) => [$this->categoryRank($a->category), $a->order, strtolower($a->title)]
            <=> [$this->categoryRank($b->category), $b->order, strtolower($b->title)]);

        return $articles;
    }

    public function find(?User $user, string $category, string $slug): ?HelpArticle
    {
        if (preg_match(self::SLUG_PATTERN, $category) !== 1 || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        $article = $this->all()[$category . '/' . $slug] ?? null;

        return $article !== null && $this->canView($user, $article) ? $article : null;
    }

    /**
     * Categories that contain at least one visible article, grouped by audience.
     *
     * @return array<string,list<array{slug:string,title:string,summary:string,audience:string,articles:list<HelpArticle>}>>
     */
    public function categoriesFor(?User $user): array
    {
        $grouped = [];
        foreach ($this->visibleTo($user) as $article) {
            $grouped[$article->audience][$article->category][] = $article;
        }

        $out = [HelpArticle::AUDIENCE_CLIENT => [], HelpArticle::AUDIENCE_INTEGRATION => []];
        foreach ($grouped as $audience => $cats) {
            foreach ($cats as $slug => $articles) {
                $out[$audience][] = $this->categoryMeta($slug, $audience) + ['articles' => $articles];
            }
        }

        return array_filter($out);
    }

    /** @return array{slug:string,title:string,summary:string,audience:string}|null */
    public function category(?User $user, string $slug): ?array
    {
        foreach ($this->categoriesFor($user) as $cats) {
            foreach ($cats as $cat) {
                if ($cat['slug'] === $slug) {
                    return $cat;
                }
            }
        }

        return null;
    }

    /**
     * Simple server-side search over title, summary and body of the articles this user may see.
     *
     * @return list<array{article:HelpArticle,excerpt:string,score:int}>
     */
    public function search(?User $user, string $query, int $limit = 30): array
    {
        $terms = array_values(array_unique(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim(mb_substr($query, 0, 120)))) ?: [],
            fn ($t) => mb_strlen($t) >= 2,
        )));
        if ($terms === []) {
            return [];
        }

        $hits = [];
        foreach ($this->visibleTo($user) as $article) {
            $title = mb_strtolower($article->title);
            $summary = mb_strtolower($article->summary);
            $body = mb_strtolower($this->plain($article->body));
            $score = 0;
            foreach ($terms as $term) {
                $in = [str_contains($title, $term) ? 5 : 0, str_contains($summary, $term) ? 3 : 0, str_contains($body, $term) ? 1 : 0];
                if (array_sum($in) === 0) {
                    continue 2; // every term must match somewhere
                }
                $score += array_sum($in);
            }
            $hits[] = ['article' => $article, 'excerpt' => $this->excerpt($article, $terms), 'score' => $score];
        }

        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['article']->title, $b['article']->title));

        return array_slice($hits, 0, $limit);
    }

    /** @return list<HelpArticle> up to $limit visible articles: explicit "related" first, then the same category */
    public function related(?User $user, HelpArticle $article, int $limit = 4): array
    {
        $all = $this->all();
        $out = [];
        foreach ($article->related as $slug) {
            foreach ($all as $candidate) {
                if ($candidate->slug === $slug && ! ($candidate->category === $article->category && $candidate->slug === $article->slug)) {
                    $out[$candidate->category . '/' . $candidate->slug] = $candidate;
                }
            }
        }
        foreach ($this->visibleTo($user) as $candidate) {
            if ($candidate->category === $article->category && $candidate->slug !== $article->slug) {
                $out[$candidate->category . '/' . $candidate->slug] ??= $candidate;
            }
        }

        return array_slice(array_values(array_filter($out, fn (HelpArticle $a) => $this->canView($user, $a))), 0, $limit);
    }

    // ------------------------------------------------------------------
    // Index (cached)
    // ------------------------------------------------------------------

    /** @return array<string,HelpArticle> keyed "category/slug" */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $files = $this->files();
        $key = 'help.index.v1.' . md5($this->path) . '.' . $this->fingerprint($files);

        $rows = Cache::rememberForever($key, function () use ($files) {
            $rows = [];
            foreach ($files as $file) {
                $article = $this->parse($file['audience'], $file['category'], $file['slug'], $file['file']);
                if ($article !== null) {
                    $rows[$article->category . '/' . $article->slug] = $article->toArray();
                }
            }

            return $rows;
        });

        return $this->memo = array_map(fn (array $row) => HelpArticle::fromArray($row), $rows);
    }

    /** @return list<array{audience:string,category:string,slug:string,file:string}> */
    private function files(): array
    {
        $files = [];
        foreach (self::AUDIENCES as $audience) {
            foreach (glob(rtrim($this->path, '/') . '/' . $audience . '/*/*.md') ?: [] as $file) {
                $category = basename(dirname($file));
                $slug = basename($file, '.md');
                if (preg_match(self::SLUG_PATTERN, $category) === 1 && preg_match(self::SLUG_PATTERN, $slug) === 1 && is_file($file) && ! is_link($file)) {
                    $files[] = ['audience' => $audience, 'category' => $category, 'slug' => $slug, 'file' => $file];
                }
            }
        }

        return $files;
    }

    /** @param list<array{file:string}> $files */
    private function fingerprint(array $files): string
    {
        $parts = [];
        foreach ($files as $f) {
            $parts[] = $f['file'] . '|' . (int) @filemtime($f['file']) . '|' . (int) @filesize($f['file']);
        }
        sort($parts);

        return md5(implode("\n", $parts));
    }

    private function parse(string $audience, string $category, string $slug, string $file): ?HelpArticle
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        if (preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $m) !== 1) {
            Log::warning('Help article ignored: no front matter', ['file' => $file]);

            return null;
        }
        $meta = $this->parseFrontMatter($m[1]);
        $title = trim((string) ($meta['title'] ?? ''));
        if ($title === '') {
            Log::warning('Help article ignored: no title', ['file' => $file]);

            return null;
        }

        $permission = isset($meta['permission']) && trim((string) $meta['permission']) !== '' ? trim((string) $meta['permission']) : null;
        if ($permission !== null && preg_match(self::PERMISSION_PATTERN, $permission) !== 1) {
            Log::warning('Help article ignored: invalid permission name', ['file' => $file]);

            return null; // fail closed
        }

        $related = array_values(array_filter(
            array_map('trim', (array) ($meta['related'] ?? [])),
            fn ($s) => preg_match(self::SLUG_PATTERN, (string) $s) === 1,
        ));

        return new HelpArticle(
            $audience, $category, $slug, $title, trim((string) ($meta['summary'] ?? '')),
            (int) ($meta['order'] ?? 100), $permission, $related, ltrim($m[2], "\n"),
        );
    }

    /**
     * Deliberately tiny front-matter reader: "key: value" lines, optional quotes, and [a, b] lists. No YAML dependency.
     *
     * @return array<string,string|list<string>>
     */
    private function parseFrontMatter(string $block): array
    {
        $meta = [];
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)\s*:\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $value = trim($m[2]);
            if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $items = array_map(fn ($v) => trim(trim($v), "\"'"), explode(',', substr($value, 1, -1)));
                $meta[$m[1]] = array_values(array_filter($items, fn ($v) => $v !== ''));
                continue;
            }
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $meta[$m[1]] = $value;
        }

        return $meta;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{slug:string,title:string,summary:string,audience:string} */
    private function categoryMeta(string $slug, string $audience): array
    {
        $cfg = (array) config('help.categories.' . $slug, []);

        return [
            'slug' => $slug,
            'title' => (string) ($cfg['title'] ?? Str::headline($slug)),
            'summary' => (string) ($cfg['summary'] ?? ''),
            'audience' => $audience,
        ];
    }

    private function categoryRank(string $slug): int
    {
        $pos = array_search($slug, array_keys((array) config('help.categories', [])), true);

        return $pos === false ? 999 : (int) $pos;
    }

    /** Markdown to plain text, good enough for searching and excerpts. */
    private function plain(string $markdown): string
    {
        $text = preg_replace('/```[a-z]*\n?/i', ' ', $markdown) ?? $markdown;
        $text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        $text = preg_replace('/[#>*_`|]+/', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @param list<string> $terms */
    private function excerpt(HelpArticle $article, array $terms): string
    {
        $text = $this->plain($article->body);
        $lower = mb_strtolower($text);
        $pos = null;
        foreach ($terms as $term) {
            $p = mb_strpos($lower, $term);
            if ($p !== false) {
                $pos = $p;
                break;
            }
        }
        if ($pos === null) {
            return $article->summary;
        }
        $start = max(0, $pos - 60);
        $snippet = mb_substr($text, $start, 180);

        return ($start > 0 ? '... ' : '') . $snippet . (mb_strlen($text) > $start + 180 ? ' ...' : '');
    }
}
