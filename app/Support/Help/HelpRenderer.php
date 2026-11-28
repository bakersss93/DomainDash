<?php

namespace App\Support\Help;

use App\Models\Setting;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown to HTML for knowledge-base articles.
 *
 * Raw HTML in the source is escaped (html_input=escape) and javascript:/data: links are dropped
 * (allow_unsafe_links=false), so an article can never inject script even if a file is edited carelessly.
 *
 * Placeholders replaced before rendering: {company} (branding company name, falling back to the application name)
 * and {app_url}. Other curly-brace text (for example Halo's {plan}) is left exactly as written.
 */
class HelpRenderer
{
    public function render(HelpArticle $article): string
    {
        $env = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new TableExtension());

        $html = (string) (new MarkdownConverter($env))->convert($this->fillPlaceholders($article->body));

        return $this->callouts($html);
    }

    public function fillPlaceholders(string $markdown): string
    {
        return str_replace(['{company}', '{app_url}'], [$this->company(), $this->appUrl()], $markdown);
    }

    public function company(): string
    {
        $name = null;
        try {
            $name = data_get((array) Setting::get('branding', []), 'company_name');
        } catch (\Throwable) {
            // settings table unavailable (very early boot): fall through to the app name
        }

        $name = trim((string) ($name ?: config('app.name', 'DomainDash')));

        return $name !== '' ? $name : 'DomainDash';
    }

    public function appUrl(): string
    {
        return rtrim((string) config('app.url', ''), '/');
    }

    /**
     * "> **Tip:** ..." style blockquotes become coloured callouts. Runs on already-escaped HTML.
     */
    private function callouts(string $html): string
    {
        return preg_replace_callback(
            '#<blockquote>\s*<p><strong>(Tip|Note|Warning|Important)(?::|\.)?</strong>#',
            function (array $m) {
                $kind = strtolower($m[1]);
                $kind = $kind === 'important' ? 'warning' : $kind;

                return '<blockquote class="help-callout help-callout--' . $kind . '"><p><strong>' . $m[1] . ':</strong>';
            },
            $html,
        ) ?? $html;
    }
}
