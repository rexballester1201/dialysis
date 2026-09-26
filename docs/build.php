<?php

declare(strict_types=1);

/**
 * Builds the documentation set in docs/ from its sources, then checks it.
 *
 *   php docs/build.php           build every document, then validate the set
 *   php docs/build.php --check   validate the files already built, change nothing
 *
 * The HTML files beside this script are OUTPUT. Their text lives in docs/src/
 * (and, for the deployment guide, in deploy/DEPLOYMENT.md). A hand edit to the
 * HTML is overwritten by the next build -- and if nobody builds, the page and
 * its source quietly disagree and nobody can tell which one is right.
 *
 * docs/src/manifest.php  the set: order, rail groups, each control block
 * docs/src/style.css     the one stylesheet, inlined into every page
 * docs/src/*.md          prose, in CommonMark + GitHub tables, with callouts:
 *                          :::stop Optional label
 *                          Markdown...
 *                          :::
 *                        and `{#id}` at the end of a heading for a stable anchor
 * docs/src/*.html        a source that already exists as HTML (the user's guide)
 *
 * Needs PHP 8.2+ and apps/api/vendor (composer install), which carries
 * league/commonmark. Exits non-zero if any document fails validation.
 */

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

const CALLOUTS = [
    'rule' => 'The central rule',
    'note' => 'Note',
    'caution' => 'Caution',
    'stop' => 'Stop',
];

main($argv);

/** @param list<string> $argv */
function main(array $argv): void
{
    $repo = dirname(__DIR__);
    $autoload = $repo.'/apps/api/vendor/autoload.php';

    if (! is_file($autoload)) {
        fwrite(STDERR, "apps/api/vendor is missing. Run `composer install` in apps/api first; the build uses its league/commonmark.\n");
        exit(2);
    }

    require $autoload;

    /** @var array{set: array<string, string>, groups: list<array{label: string, docs: list<string>}>, docs: list<array<string, mixed>>} $manifest */
    $manifest = require __DIR__.'/src/manifest.php';

    // --only=a.html,b.html rebuilds just those while writing; the whole set is
    // still validated afterwards, so links into unbuilt pages are reported.
    $only = null;

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--only=')) {
            $only = explode(',', substr($arg, 7));
        }
    }

    if (! in_array('--check', $argv, true)) {
        $converter = converter();
        $css = (string) file_get_contents(__DIR__.'/src/style.css');

        foreach ($manifest['docs'] as $doc) {
            if ($only !== null && ! in_array($doc['file'], $only, true)) {
                continue;
            }

            file_put_contents(__DIR__.'/'.$doc['file'], render($doc, $manifest, $converter, $css, $repo));
            echo "built   {$doc['file']}  <-  {$doc['source']}\n";
        }
    }

    $problems = array_values(array_unique(validate($manifest, $repo)));

    if ($problems !== []) {
        echo "\n".count($problems)." problem(s):\n";

        foreach ($problems as $problem) {
            echo "  - {$problem}\n";
        }

        exit(1);
    }

    echo "\nvalid   ".count($manifest['docs'])." documents: tags balanced, ids unique, every internal and cross-document link resolves, no double-escaped entities.\n";
}

function converter(): MarkdownConverter
{
    $environment = new Environment([
        // The sources are ours; raw HTML is allowed so a table cell or a
        // callout can carry markup Markdown has no syntax for.
        'html_input' => 'allow',
        'allow_unsafe_links' => false,
    ]);
    $environment->addExtension(new CommonMarkCoreExtension());
    $environment->addExtension(new GithubFlavoredMarkdownExtension());

    return new MarkdownConverter($environment);
}

/* ------------------------------------------------------------------------ */
/*  Rendering                                                                */
/* ------------------------------------------------------------------------ */

/**
 * @param  array<string, mixed>  $doc
 * @param  array<string, mixed>  $manifest
 */
function render(array $doc, array $manifest, MarkdownConverter $converter, string $css, string $repo): string
{
    $sourcePath = $repo.'/'.$doc['source'];

    if (! is_file($sourcePath)) {
        throw new RuntimeException("{$doc['file']}: source {$doc['source']} does not exist.");
    }

    $source = (string) file_get_contents($sourcePath);

    [$title, $standfirst, $body] = str_ends_with($doc['source'], '.md')
        ? fromMarkdown($source, $converter)
        : fromHtmlFragment($source, $doc['source']);

    $title = $doc['h1'] ?? $title ?? $doc['title'];
    $standfirst = $doc['standfirst'] ?? $standfirst;

    // A source written to be read on its own (deploy/DEPLOYMENT.md) opens with
    // its own introduction; that paragraph becomes the page's standfirst.
    if ($standfirst === null && ($doc['lift_standfirst'] ?? false)
        && preg_match('~\A\s*<p>(.*?)</p>~s', $body, $first) === 1) {
        $standfirst = trim($first[1]);
        $body = trim(substr($body, strlen($first[0])));
    }

    if (isset($doc['link_base'])) {
        $body = rebaseLinks($body, $doc['link_base'], $manifest);
    }

    $toc = [];
    $body = headings($body, (bool) ($doc['numbered'] ?? true), (int) ($doc['depth'] ?? 2), $toc);
    $body = tables($body);
    $body = str_replace('<pre>', '<pre tabindex="0">', $body);

    return shell($doc, $manifest, $title, $standfirst, $body, $toc, $css);
}

/**
 * Markdown to HTML, lifting the first `# ` heading out as the page title and
 * turning `:::kind` blocks into callouts.
 *
 * @return array{0: ?string, 1: ?string, 2: string}
 */
function fromMarkdown(string $markdown, MarkdownConverter $converter): array
{
    $markdown = str_replace("\r\n", "\n", $markdown);
    $title = null;

    if (preg_match('/\A\s*# (.+)\n/', $markdown, $match) === 1) {
        $title = trim($match[1]);
        $markdown = substr($markdown, strlen($match[0]));
    }

    $blocks = [];

    $markdown = (string) preg_replace_callback(
        '/^:::([a-z]+)[ \t]*(.*?)[ \t]*\n(.*?)\n:::[ \t]*$/ms',
        function (array $m) use ($converter, &$blocks): string {
            $kind = $m[1];

            if (! array_key_exists($kind, CALLOUTS)) {
                throw new RuntimeException("Unknown callout ':::{$kind}'. Known: ".implode(', ', array_keys(CALLOUTS)).'.');
            }

            $label = $m[2] !== '' ? $m[2] : CALLOUTS[$kind];
            $inner = (string) $converter->convert($m[3]);
            $blocks[] = '<aside class="callout callout-'.$kind.'"><p class="callout-label">'
                .inline($label, $converter).'</p>'.trim($inner).'</aside>';

            return "\n<!--callout:".(count($blocks) - 1)."-->\n";
        },
        $markdown,
    );

    $html = (string) $converter->convert($markdown);

    $html = (string) preg_replace_callback(
        '/<!--callout:(\d+)-->/',
        fn (array $m): string => $blocks[(int) $m[1]],
        $html,
    );

    return [$title, null, trim($html)];
}

/** A label rendered as inline Markdown, without the wrapping paragraph. */
function inline(string $text, MarkdownConverter $converter): string
{
    $html = trim((string) $converter->convert($text));

    return (string) preg_replace('~^<p>(.*)</p>$~s', '$1', $html);
}

/**
 * A source that is already HTML. Its <h1> and <p class="standfirst"> become the
 * page header; everything else is the body, unchanged.
 *
 * @return array{0: ?string, 1: ?string, 2: string}
 */
function fromHtmlFragment(string $html, string $name): array
{
    $html = str_replace("\r\n", "\n", $html);
    $html = (string) preg_replace('~<!--.*?-->~s', '', $html);

    if (preg_match('~<h1>(.*?)</h1>~s', $html, $h1) !== 1) {
        throw new RuntimeException("{$name}: an HTML source needs an <h1>.");
    }

    $html = str_replace($h1[0], '', $html);
    $standfirst = null;

    if (preg_match('~<p class="standfirst">(.*?)</p>~s', $html, $sf) === 1) {
        $standfirst = trim($sf[1]);
        $html = str_replace($sf[0], '', $html);
    }

    return [trim($h1[1]), $standfirst, trim($html)];
}

/**
 * Point a source's relative links back at where they were written from.
 *
 * deploy/DEPLOYMENT.md links to files beside it; rendered into docs/, those
 * links would resolve against the wrong folder.
 *
 * @param  array<string, mixed>  $manifest
 */
function rebaseLinks(string $html, string $base, array $manifest): string
{
    $set = array_column($manifest['docs'], 'file');

    return (string) preg_replace_callback(
        '~\shref="([^"#][^"]*)"~',
        function (array $m) use ($base, $set): string {
            $href = $m[1];
            $path = explode('#', $href, 2)[0];

            if (preg_match('~^[a-z]+:~i', $href) === 1 || in_array($path, $set, true)) {
                return $m[0];
            }

            return ' href="'.$base.$href.'"';
        },
        $html,
    );
}

/**
 * Give every h2-h4 an id, number the h2s, and collect the contents.
 *
 * An explicit `{#id}` at the end of a heading wins, then an id already on the
 * tag, then one made from the text. Ids are made unique within the page.
 *
 * @param  list<array{level: int, id: string, html: string, children: list<array{id: string, html: string}>}>  $toc
 */
function headings(string $html, bool $numbered, int $depth, array &$toc): string
{
    $seen = [];
    $section = 0;

    return (string) preg_replace_callback(
        '~<h([2-4])((?:\s[^>]*)?)>(.*?)</h\1>~s',
        function (array $m) use (&$seen, &$section, &$toc, $numbered, $depth): string {
            $level = (int) $m[1];
            $attributes = $m[2];
            $inner = trim($m[3]);
            $id = null;

            if (preg_match('~\s*\{#([a-z0-9][a-z0-9-]*)\}\s*$~', $inner, $explicit) === 1) {
                $id = $explicit[1];
                $inner = trim(substr($inner, 0, -strlen($explicit[0])));
            }

            if (preg_match('~\sid="([^"]+)"~', $attributes, $existing) === 1) {
                $id ??= $existing[1];
                $attributes = str_replace($existing[0], '', $attributes);
            }

            $id ??= slug(strip_tags($inner));
            $unique = $id;

            for ($n = 2; isset($seen[$unique]); $n++) {
                $unique = "{$id}-{$n}";
            }

            $seen[$unique] = true;

            if ($level === 2 && $numbered) {
                $section++;
                $inner = '<span class="secnum">'.$section.'</span> '.$inner;
            }

            if ($level === 2) {
                $toc[] = ['level' => 2, 'id' => $unique, 'html' => $inner, 'children' => []];
            } elseif ($level === 3 && $depth >= 3 && $toc !== []) {
                $toc[count($toc) - 1]['children'][] = ['id' => $unique, 'html' => $inner];
            }

            return "<h{$level} id=\"{$unique}\"{$attributes}>{$inner}</h{$level}>";
        },
        $html,
    );
}

function slug(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text));
    $text = trim($text, '-');

    if (strlen($text) > 48) {
        $text = rtrim(substr($text, 0, 48), '-');
    }

    return $text === '' ? 'section' : $text;
}

/**
 * Wrap every table so it scrolls sideways inside itself, never the page.
 *
 * A table whose last header cell reads "Done" is a checklist, and gets an empty
 * box in that column for a pen. The user's guide wraps its own tables in
 * `.tablewrap`; those are re-wrapped the same way rather than nested.
 */
function tables(string $html): string
{
    $lastHeading = 'Table';

    return (string) preg_replace_callback(
        '~(<h[2-4]\b[^>]*>(.*?)</h[2-4]>)|<div class="tablewrap">\s*(<table\b.*?</table>)\s*</div>|(<table\b.*?</table>)~s',
        function (array $m) use (&$lastHeading): string {
            if ($m[1] !== '') {
                $lastHeading = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                return $m[1];
            }

            $table = ($m[3] ?? '') !== '' ? $m[3] : $m[4];

            if (preg_match_all('~<th\b[^>]*>(.*?)</th>~s', $table, $headers) > 0
                && trim(strip_tags((string) end($headers[1]))) === 'Done') {
                $table = (string) preg_replace('~^<table\b~', '<table class="ticks"', $table);
            }

            $label = htmlspecialchars('Table: '.preg_replace('/^\d+\s+/', '', $lastHeading), ENT_QUOTES, 'UTF-8');

            return '<div class="table-wrap" role="region" tabindex="0" aria-label="'.$label.'">'.$table.'</div>';
        },
        $html,
    );
}

/**
 * @param  array<string, mixed>  $doc
 * @param  array<string, mixed>  $manifest
 * @param  list<array{level: int, id: string, html: string, children: list<array{id: string, html: string}>}>  $toc
 */
function shell(array $doc, array $manifest, string $title, ?string $standfirst, string $body, array $toc, string $css): string
{
    $set = $manifest['set'];
    $issued = new DateTimeImmutable($doc['issued']);
    $issuedLong = $issued->format('j F Y');
    $pageTitle = $doc['file'] === 'index.html'
        ? $doc['title'].' · '.$set['system']
        : $doc['title'].' · '.$doc['ref'];
    $running = cssString("{$doc['ref']} · {$doc['title']} · {$doc['version']} · {$issuedLong}");

    $control = '';

    foreach ([
        'Reference' => esc($doc['ref']),
        'Version' => esc($doc['version']),
        'Issued' => '<time datetime="'.$issued->format('Y-m-d').'">'.$issuedLong.'</time>',
        'Written for' => esc($doc['written_for']),
        'Applies to' => esc($doc['applies_to']),
        'Source' => '<code>'.esc($doc['source']).'</code>',
    ] as $term => $value) {
        $control .= "<div><dt>{$term}</dt><dd>{$value}</dd></div>";
    }

    $contents = '';

    if ($toc !== []) {
        $items = '';

        foreach ($toc as $entry) {
            $children = '';

            if ($entry['children'] !== []) {
                $children = '<ol>';

                foreach ($entry['children'] as $child) {
                    $children .= '<li><a href="#'.$child['id'].'">'.$child['html'].'</a></li>';
                }

                $children .= '</ol>';
            }

            $items .= '<li><a href="#'.$entry['id'].'">'.$entry['html'].'</a>'.$children.'</li>';
        }

        $contents = '<nav class="contents" aria-label="Contents"><p class="contents-title">Contents</p><ol>'.$items.'</ol></nav>';
    }

    $standfirstHtml = $standfirst === null ? '' : '<p class="standfirst">'.$standfirst.'</p>';

    return '<!doctype html>'."\n"
        .'<html lang="en-GB">'."\n"
        .'<head>'."\n"
        .'<meta charset="utf-8">'."\n"
        .'<meta name="viewport" content="width=device-width, initial-scale=1">'."\n"
        .'<meta name="generator" content="docs/build.php">'."\n"
        .'<meta name="description" content="'.esc($doc['description']).'">'."\n"
        .'<title>'.esc($pageTitle).'</title>'."\n"
        .'<link rel="preconnect" href="https://fonts.googleapis.com">'."\n"
        .'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'."\n"
        .'<link rel="stylesheet" href="'.esc($set['fonts']).'">'."\n"
        ."<style>\n".$css."\n@page { @bottom-left { content: {$running}; } }\n</style>\n"
        .'</head>'."\n"
        .'<body>'."\n"
        .'<a class="skip" href="#main">Skip to the document</a>'."\n"
        .'<div class="frame">'."\n"
        .rail($manifest, $doc['file'])."\n"
        .'<main id="main" tabindex="-1">'."\n"
        .'<header class="doc-head">'
        .'<p class="eyebrow"><span class="eyebrow-ref">'.esc($doc['ref']).'</span> '.esc($doc['title']).'</p>'
        .'<h1>'.$title.'</h1>'
        .$standfirstHtml
        .'<dl class="control">'.$control.'</dl>'
        .'</header>'."\n"
        .$contents."\n"
        .'<article class="doc-body">'."\n".$body."\n".'</article>'."\n"
        .'<footer class="doc-foot">'
        .'<p class="doc-foot-ref"><span>'.esc($doc['ref']).'</span> '.esc($doc['title']).' · '.esc($doc['version']).' · '.$issuedLong.'</p>'
        .'<p>Generated by <code>docs/build.php</code> from <code>'.esc($doc['source']).'</code>. Edit the source and rebuild; a change made to this file is overwritten by the next build. <a href="index.html#rebuild">How to rebuild the set</a>.</p>'
        .'</footer>'."\n"
        .'</main>'."\n"
        .'</div>'."\n"
        .'</body>'."\n"
        .'</html>'."\n";
}

/** @param array<string, mixed> $manifest */
function rail(array $manifest, string $current): string
{
    $byFile = array_column($manifest['docs'], null, 'file');
    $html = '<nav class="rail" aria-label="Documentation set">'
        .'<a class="rail-title" href="index.html"><span class="rail-system">'.esc($manifest['set']['system']).'</span>'
        .'<span class="rail-set">'.esc($manifest['set']['label']).'</span></a>';

    foreach ($manifest['groups'] as $group) {
        $html .= '<div class="rail-group"><p class="rail-group-label">'.esc($group['label']).'</p><ul>';

        foreach ($group['docs'] as $file) {
            $doc = $byFile[$file] ?? throw new RuntimeException("Rail lists {$file}, which is not in the manifest.");
            $currentAttr = $file === $current ? ' aria-current="page"' : '';
            $html .= '<li><a href="'.esc($file).'"'.$currentAttr.'>'
                .'<span class="rail-ref">'.esc($doc['ref']).'</span>'
                .'<span class="rail-name">'.esc($doc['short']).'</span></a></li>';
        }

        $html .= '</ul></div>';
    }

    return $html.'</nav>';
}

function esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cssString(string $text): string
{
    return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $text).'"';
}

/* ------------------------------------------------------------------------ */
/*  Validation                                                               */
/* ------------------------------------------------------------------------ */

/**
 * @param  array<string, mixed>  $manifest
 * @return list<string>
 */
function validate(array $manifest, string $repo): array
{
    $problems = [];
    $pages = [];
    $ids = [];

    foreach ($manifest['docs'] as $doc) {
        $path = __DIR__.'/'.$doc['file'];

        if (! is_file($path)) {
            $problems[] = "{$doc['file']}: not built";

            continue;
        }

        $pages[$doc['file']] = (string) file_get_contents($path);
    }

    $grouped = array_merge(...array_column($manifest['groups'], 'docs'));

    foreach (array_column($manifest['docs'], 'file') as $file) {
        if (! in_array($file, $grouped, true)) {
            $problems[] = "{$file}: missing from the rail groups in the manifest";
        }
    }

    foreach ($pages as $file => $html) {
        if (preg_match('/\A<!doctype html>/i', $html) !== 1) {
            $problems[] = "{$file}: does not start with <!doctype html>";
        }

        if (preg_match('~<title>([^<]*)</title>~', $html, $t) !== 1 || trim($t[1]) === '') {
            $problems[] = "{$file}: no <title>";
        }

        if (preg_match('~<html lang="[A-Za-z-]+"~', $html) !== 1) {
            $problems[] = "{$file}: <html> has no lang";
        }

        if (preg_match_all('~<h1\b~', $html) !== 1) {
            $problems[] = "{$file}: needs exactly one <h1>";
        }

        foreach (balance($html) as $problem) {
            $problems[] = "{$file}: {$problem}";
        }

        preg_match_all('~\sid="([^"]*)"~', $html, $found);
        $ids[$file] = [];

        foreach ($found[1] as $id) {
            if ($id === '') {
                $problems[] = "{$file}: an empty id";
            } elseif (isset($ids[$file][$id])) {
                $problems[] = "{$file}: id \"{$id}\" is used twice";
            }

            $ids[$file][$id] = true;
        }

        $current = preg_match_all('~<a href="([^"]+)" aria-current="page">~', $html, $cur);

        if ($current !== 1 || $cur[1][0] !== $file) {
            $problems[] = "{$file}: the rail must mark exactly this page with aria-current";
        }

        $text = textOnly($html);

        // Prose only: a code sample may legitimately show the source syntax.
        $prose = (string) preg_replace('~<(pre|code)\b[^>]*>.*?</\1>~s', '', $text);

        if (preg_match_all('~&amp;(?:amp|lt|gt|quot|apos|nbsp|mdash|ndash|middot|rarr|#\d+|#x[0-9a-f]+);~i', $html, $double) > 0) {
            $problems[] = "{$file}: double-escaped entities: ".implode(', ', array_unique($double[0]));
        }

        if (preg_match_all('~&(?![A-Za-z][A-Za-z0-9]*;|#\d+;|#x[0-9A-Fa-f]+;)~', $text) > 0) {
            $problems[] = "{$file}: a bare & that should be &amp;";
        }

        if (preg_match('~:::(?:[a-z]+)?\s|\{#[a-z0-9-]+\}~', $prose, $left) === 1 || str_contains($html, '<!--callout')) {
            $problems[] = "{$file}: unrendered source syntax left in the page: ".trim($left[0] ?? '<!--callout');
        }

        if (preg_match('~\b(?:TODO|TBD|FIXME|XXX)\b|lorem ipsum~', $prose, $placeholder) === 1) {
            $problems[] = "{$file}: placeholder text \"{$placeholder[0]}\"";
        }
    }

    foreach ($pages as $file => $html) {
        preg_match_all('~\s(?:href|src)="([^"]*)"~', $html, $links);

        foreach ($links[1] as $raw) {
            $href = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($href === '') {
                $problems[] = "{$file}: an empty link";

                continue;
            }

            if (preg_match('~^(?:https://|mailto:)~', $href) === 1) {
                continue;
            }

            if (preg_match('~^[a-z]+:~i', $href) === 1) {
                $problems[] = "{$file}: link \"{$href}\" uses a scheme other than https or mailto";

                continue;
            }

            [$path, $fragment] = array_pad(explode('#', $href, 2), 2, null);
            $path = explode('?', (string) $path, 2)[0];

            if ($path === '') {
                if ($fragment !== null && ! isset($ids[$file][$fragment])) {
                    $problems[] = "{$file}: dead anchor #{$fragment}";
                }

                continue;
            }

            if (isset($pages[$path])) {
                if ($fragment !== null && $fragment !== '' && ! isset($ids[$path][$fragment])) {
                    $problems[] = "{$file}: links to {$path}#{$fragment}, which has no such id";
                }

                continue;
            }

            if (! file_exists(__DIR__.'/'.rawurldecode($path))) {
                $problems[] = "{$file}: links to {$path}, which does not exist";
            }
        }

        preg_match_all('~\s(?:aria-labelledby|aria-describedby|for)="([^"]+)"~', $html, $references);

        foreach ($references[1] as $reference) {
            foreach (preg_split('/\s+/', $reference) ?: [] as $id) {
                if (! isset($ids[$file][$id])) {
                    $problems[] = "{$file}: refers to id \"{$id}\", which does not exist";
                }
            }
        }
    }

    return $problems;
}

/** The page with comments, <style> and <script> contents removed. */
function textOnly(string $html): string
{
    $html = (string) preg_replace('~<!--.*?-->~s', '', $html);

    return (string) preg_replace('~(<(style|script)\b[^>]*>).*?(</\2>)~is', '$1$3', $html);
}

/**
 * Every non-void element opened is closed, in order, and nothing is closed
 * that was not opened.
 *
 * @return list<string>
 */
function balance(string $html): array
{
    $problems = [];
    $html = textOnly($html);
    $html = (string) preg_replace('~<!doctype[^>]*>~i', '', $html);

    preg_match_all(
        '~<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~',
        $html,
        $tags,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );

    $stack = [];

    foreach ($tags as $tag) {
        $closing = $tag[1][0] === '/';
        $name = strtolower($tag[2][0]);
        $line = substr_count($html, "\n", 0, $tag[0][1]) + 1;

        if (in_array($name, VOID_ELEMENTS, true)) {
            continue;
        }

        if (str_ends_with(rtrim($tag[3][0]), '/')) {
            $problems[] = "<{$name} /> is not a void element (line {$line})";

            continue;
        }

        if (! $closing) {
            $stack[] = [$name, $line];

            continue;
        }

        if ($stack === []) {
            $problems[] = "stray </{$name}> (line {$line})";

            continue;
        }

        [$open, $openedAt] = $stack[count($stack) - 1];

        if ($open === $name) {
            array_pop($stack);

            continue;
        }

        $problems[] = "</{$name}> on line {$line} closes <{$open}> opened on line {$openedAt}";

        $names = array_column($stack, 0);

        if (in_array($name, $names, true)) {
            while ($stack !== [] && array_pop($stack)[0] !== $name) {
                // unwind to the element this tag actually closes
            }
        }
    }

    foreach ($stack as [$name, $line]) {
        $problems[] = "<{$name}> opened on line {$line} is never closed";
    }

    return $problems;
}
