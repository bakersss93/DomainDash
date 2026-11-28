<style>
    .help-search { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1.25rem; max-width: 40rem; }
    .help-search .ui-input { flex: 1; min-width: 12rem; }
    .help-crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 0.375rem; font-size: var(--dd-text-sm); color: var(--dd-text-soft); margin: 0 0 0.75rem; padding: 0; list-style: none; }
    .help-crumbs li + li::before { content: "/"; margin-right: 0.375rem; color: var(--dd-text-faint); }
    .help-crumbs a { color: var(--dd-accent-text); text-decoration: none; }
    .help-crumbs a:hover { text-decoration: underline; }
    .help-section-title { font-size: var(--dd-text-lg); font-weight: 600; color: var(--dd-text); margin: 1.5rem 0 0.25rem; }
    .help-section-note { color: var(--dd-text-soft); font-size: var(--dd-text-sm); margin: 0 0 0.75rem; }
    .help-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 19rem), 1fr)); margin-bottom: 0.5rem; }
    .help-cat { display: flex; flex-direction: column; gap: 0.5rem; padding: 1.1rem 1.25rem; background: var(--dd-surface); border: 1px solid var(--dd-border); border-radius: var(--dd-radius-lg); box-shadow: var(--dd-shadow-xs); }
    .help-cat h3 { margin: 0; font-size: var(--dd-text-base); font-weight: 600; }
    .help-cat h3 a { color: var(--dd-text); text-decoration: none; }
    .help-cat h3 a:hover { color: var(--dd-accent-text); }
    .help-cat p { margin: 0; color: var(--dd-text-soft); font-size: var(--dd-text-sm); }
    .help-cat ul { margin: 0.25rem 0 0; padding: 0; list-style: none; display: grid; gap: 0.25rem; }
    .help-cat li a { color: var(--dd-accent-text); text-decoration: none; font-size: var(--dd-text-sm); }
    .help-cat li a:hover { text-decoration: underline; }
    .help-results { display: grid; gap: 0.75rem; }
    .help-result { padding: 0.9rem 1.1rem; background: var(--dd-surface); border: 1px solid var(--dd-border); border-radius: var(--dd-radius-lg); }
    .help-result a { color: var(--dd-accent-text); font-weight: 600; text-decoration: none; }
    .help-result a:hover { text-decoration: underline; }
    .help-result p { margin: 0.25rem 0 0; color: var(--dd-text-soft); font-size: var(--dd-text-sm); overflow-wrap: anywhere; }
    .help-layout { display: grid; gap: 1.25rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
    @media (min-width: 64rem) { .help-layout { grid-template-columns: minmax(0, 1fr) 17rem; } }
    .help-side { display: grid; gap: 1rem; }
    .help-side h2 { margin: 0 0 0.5rem; font-size: var(--dd-text-base); font-weight: 600; color: var(--dd-text); }
    .help-side ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 0.4rem; }
    .help-side li a { color: var(--dd-accent-text); text-decoration: none; font-size: var(--dd-text-sm); }
    .help-side li a:hover { text-decoration: underline; }
    .help-article { padding: 1.5rem; background: var(--dd-surface); border: 1px solid var(--dd-border); border-radius: var(--dd-radius-lg); box-shadow: var(--dd-shadow-xs); min-width: 0; }
    .help-prose { color: var(--dd-text); font-size: var(--dd-text-base); line-height: 1.65; overflow-wrap: anywhere; }
    .help-prose > :first-child { margin-top: 0; }
    .help-prose h1 { display: none; }
    .help-prose h2 { font-size: var(--dd-text-xl); font-weight: 600; margin: 1.75rem 0 0.5rem; }
    .help-prose h3 { font-size: var(--dd-text-lg); font-weight: 600; margin: 1.25rem 0 0.4rem; }
    .help-prose p, .help-prose ul, .help-prose ol, .help-prose table, .help-prose pre { margin: 0 0 0.9rem; }
    .help-prose ul { padding-left: 1.5rem; list-style: disc; }
    .help-prose ol { padding-left: 1.5rem; list-style: decimal; }
    .help-prose li > ul { list-style: circle; }
    .help-prose li { margin: 0.25rem 0; }
    .help-prose li > ul, .help-prose li > ol { margin: 0.25rem 0 0; }
    .help-prose a { color: var(--dd-accent-text); text-decoration: underline; }
    .help-prose code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.875em; background: var(--dd-surface-muted); border: 1px solid var(--dd-border); border-radius: var(--dd-radius-sm); padding: 0.1rem 0.35rem; }
    .help-prose pre { background: var(--dd-surface-muted); border: 1px solid var(--dd-border); border-radius: var(--dd-radius); padding: 0.85rem 1rem; overflow-x: auto; }
    .help-prose pre code { background: none; border: 0; padding: 0; font-size: 0.85rem; }
    .help-prose table { border-collapse: collapse; display: block; overflow-x: auto; max-width: 100%; }
    .help-prose th, .help-prose td { border: 1px solid var(--dd-border); padding: 0.45rem 0.7rem; text-align: left; vertical-align: top; }
    .help-prose th { background: var(--dd-surface-muted); font-weight: 600; }
    .help-prose blockquote { margin: 0 0 0.9rem; padding: 0.7rem 1rem; border-left: 4px solid var(--dd-border-strong); background: var(--dd-surface-soft); border-radius: 0 var(--dd-radius) var(--dd-radius) 0; }
    .help-prose blockquote p:last-child { margin-bottom: 0; }
    .help-callout--tip { border-left-color: var(--dd-success); background: var(--dd-status-success-bg); color: var(--dd-status-success-fg); }
    .help-callout--note { border-left-color: var(--dd-info); background: var(--dd-status-info-bg); color: var(--dd-status-info-fg); }
    .help-callout--warning { border-left-color: var(--dd-warning); background: var(--dd-status-warning-bg); color: var(--dd-status-warning-fg); }
    .help-callout code { color: inherit; }
    .help-stuck { display: grid; gap: 0.5rem; }
    .help-stuck p { margin: 0; color: var(--dd-text-soft); font-size: var(--dd-text-sm); }
</style>
