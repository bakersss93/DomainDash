import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/**
 * Colours resolve to CSS custom properties declared in resources/css/app.css
 * (light on :root, dark on html.dark), so `bg-surface`, `text-muted`,
 * `border-subtle`, `bg-primary` etc. follow the active theme automatically.
 * See docs/review/09-ui-design-system.md.
 */
const token = (name) => `var(--dd-${name})`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Classes assembled at runtime (e.g. 'dd-status-'.$status) are invisible to the scanner.
    safelist: [
        { pattern: /^ui-badge--(success|warning|danger|info|neutral|primary)$/ },
        { pattern: /^ui-alert--(success|warning|danger|info)$/ },
        { pattern: /^dd-(badge|status|alert-box)(-|--)?(success|warning|danger|info|muted|active|suspended|terminated|unknown|error)?$/ },
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Re-skin legacy Jetstream utility colours onto the brand: indigo -> Jargon green,
                // gray -> the charcoal-tinted neutral scale (matches --dd-* neutrals).
                indigo: {
                    50: '#f0f7f2', 100: '#dcede2', 200: '#bbdac6', 300: '#8fc2a3', 400: '#5ea67c',
                    500: '#3d8f5f', 600: '#2f7d4b', 700: '#266a3e', 800: '#1f5533', 900: '#19442a', 950: '#0e2818',
                },
                gray: {
                    50: '#f9fafb', 100: '#f5f6f8', 200: '#e2e5ea', 300: '#cbd1da', 400: '#8d939e',
                    500: '#6f7581', 600: '#565c68', 700: '#3a3e47', 800: '#23252b', 900: '#17181c', 950: '#101114',
                },
                canvas: token('canvas'),
                surface: {
                    DEFAULT: token('surface'),
                    muted: token('surface-muted'),
                    soft: token('surface-soft'),
                },
                ink: {
                    DEFAULT: token('text'),
                    muted: token('text-soft'),
                    faint: token('text-faint'),
                },
                // `text-muted` / `bg-muted` -> secondary text colour / muted surface
                muted: token('text-soft'),
                subtle: token('border'),
                strong: token('border-strong'),
                primary: {
                    DEFAULT: token('accent'),
                    hover: token('accent-strong'),
                    soft: token('accent-soft'),
                    fg: token('accent-contrast'),
                    text: token('accent-text'),
                },
                success: { DEFAULT: token('success'), soft: token('status-success-bg'), fg: token('status-success-fg') },
                warning: { DEFAULT: token('warning'), soft: token('status-warning-bg'), fg: token('status-warning-fg') },
                danger: { DEFAULT: token('danger'), soft: token('status-danger-bg'), fg: token('status-danger-fg') },
                info: { DEFAULT: token('info'), soft: token('status-info-bg'), fg: token('status-info-fg') },
                sidebar: { DEFAULT: token('sidebar'), soft: token('sidebar-soft') },
            },
            borderRadius: {
                sm: token('radius-sm'),
                DEFAULT: token('radius'),
                md: token('radius'),
                lg: token('radius-lg'),
                xl: token('radius-xl'),
            },
            boxShadow: {
                xs: token('shadow-xs'),
                sm: token('shadow-sm'),
                DEFAULT: token('shadow-sm'),
                md: token('shadow-md'),
                lg: token('shadow-lg'),
                overlay: token('shadow-overlay'),
            },
            fontSize: {
                // Typography scale: [size, line-height]
                '2xs': ['0.6875rem', '1rem'],
            },
            ringColor: {
                DEFAULT: token('focus'),
            },
        },
    },

    plugins: [forms, typography],
};
