#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate docs/pages/capability-comparison.html from capability matrices.
 *
 * Compares tracked PHP language/stdlib features (reference = php-src / Zend PHP)
 * with php-compiler VM / JIT / AOT columns from docs/capabilities*.md.
 *
 * Usage:
 *   php script/generate-pages-capability-comparison.php
 *   php script/generate-pages-capability-comparison.php --check
 */

$root = dirname(__DIR__);
$outFile = $root . '/docs/pages/capability-comparison.html';
$check = in_array('--check', $argv, true);

require $root . '/vendor/autoload.php';

/**
 * @return list<array{name: string, vm: string, jit: string, aot: string, issue: string, notes: string, section: string}>
 */
function parseCapabilityMarkdown(string $path, string $onlySectionPrefix = ''): array
{
    $content = (string) file_get_contents($path);
    $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
    $rows = [];
    $section = '';
    $headers = null;

    foreach ($lines as $line) {
        if (preg_match('/^## (.+)$/', $line, $m)) {
            $section = trim($m[1]);
            $headers = null;
            continue;
        }
        if ('' !== $onlySectionPrefix && '' !== $section && !str_starts_with($section, $onlySectionPrefix)) {
            $headers = null;
            continue;
        }
        if (!str_starts_with(trim($line), '|')) {
            continue;
        }
        if (preg_match('/^\|\s*[-:]+/', $line)) {
            continue;
        }
        $cells = array_map(static fn (string $c): string => trim($c), explode('|', trim($line, " \t|")));
        if ([] === $cells || '' === $cells[0]) {
            continue;
        }
        if (in_array($cells[0], ['Function', 'Construct', 'Builtin'], true)) {
            $headers = $cells;
            continue;
        }
        if (null === $headers) {
            continue;
        }
        $row = [];
        foreach ($headers as $i => $header) {
            $row[$header] = $cells[$i] ?? '';
        }
        $name = $row['Function'] ?? $row['Construct'] ?? $cells[0];
        $rows[] = [
            'name' => $name,
            'vm' => $row['VM'] ?? '',
            'jit' => $row['JIT'] ?? '',
            'aot' => $row['AOT'] ?? '',
            'issue' => $row['Issue'] ?? '',
            'notes' => $row['Notes'] ?? ($row['Module'] ?? ''),
            'section' => $section,
        ];
    }

    return $rows;
}

function normalizeCell(string $value): string
{
    return strtolower(trim($value));
}

/** @return 'full'|'partial'|'gap'|'na' */
function classifySupport(string $vm, string $jit, string $aot): string
{
    $vmN = normalizeCell($vm);
    $jitN = normalizeCell($jit);
    $aotN = normalizeCell($aot);

    if ('n/a' === $vmN && 'n/a' === $jitN && 'n/a' === $aotN) {
        return 'na';
    }
    $isYes = static fn (string $v): bool => 'yes' === $v;
    $hasVm = static fn (string $v): bool => in_array($v, ['yes', 'partial'], true);

    if ($isYes($vmN) && $isYes($jitN) && $isYes($aotN)) {
        return 'full';
    }
    if ($hasVm($vmN)) {
        return 'partial';
    }

    return 'gap';
}

function cellClass(string $value): string
{
    return match (normalizeCell($value)) {
        'yes' => 'status-yes',
        'no' => 'status-no',
        'partial' => 'status-wip',
        default => '',
    };
}

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function issueCell(string $issue): string
{
    if ('' === $issue) {
        return '—';
    }
    if (preg_match('/#(\d+)/', $issue, $m)) {
        $n = $m[1];
        return '<a href="https://github.com/PurHur/php-compiler/issues/'.$n.'">#'.$n.'</a>';
    }

    return esc($issue);
}

/**
 * @param list<array{name: string, vm: string, jit: string, aot: string, issue: string, notes: string, section: string}> $rows
 *
 * @return array{total: int, full: int, partial: int, gap: int}
 */
function summarize(array $rows): array
{
    $summary = ['total' => count($rows), 'full' => 0, 'partial' => 0, 'gap' => 0];
    foreach ($rows as $row) {
        $c = classifySupport($row['vm'], $row['jit'], $row['aot']);
        if ('full' === $c) {
            ++$summary['full'];
        } elseif ('partial' === $c) {
            ++$summary['partial'];
        } elseif ('gap' === $c) {
            ++$summary['gap'];
        }
    }

    return $summary;
}

/**
 * @param list<array{name: string, vm: string, jit: string, aot: string, issue: string, notes: string, section: string}> $rows
 */
function renderTable(array $rows, string $id): string
{
    $html = '<div class="table-wrap capability-table-wrap" data-table="'.$id.'">'."\n";
    $html .= '<table class="capability-compare"><thead><tr>';
    $html .= '<th>Feature</th><th>PHP</th><th>VM</th><th>JIT</th><th>AOT</th><th>Issue</th>';
    $html .= '</tr></thead><tbody>'."\n";

    foreach ($rows as $row) {
        $support = classifySupport($row['vm'], $row['jit'], $row['aot']);
        $html .= '<tr data-support="'.esc($support).'">';
        $html .= '<td><code>'.esc($row['name']).'</code></td>';
        $html .= '<td class="status-yes">yes</td>';
        $html .= '<td class="'.cellClass($row['vm']).'">'.esc($row['vm']).'</td>';
        $html .= '<td class="'.cellClass($row['jit']).'">'.esc($row['jit']).'</td>';
        $html .= '<td class="'.cellClass($row['aot']).'">'.esc($row['aot']).'</td>';
        $html .= '<td>'.issueCell($row['issue']).'</td>';
        $html .= "</tr>\n";
    }

    $html .= "</tbody></table></div>\n";

    return $html;
}

$languageRows = parseCapabilityMarkdown($root.'/docs/capabilities-syntax.md');
// Drop example-app curated sections; keep language + stdlib-array subsection rows.
$languageRows = array_values(array_filter(
    $languageRows,
    static fn (array $r): bool => !preg_match(
        '/^(Web north-star|OOP reference|Sessions reference|File upload reference|Throws reference)/',
        $r['section']
    )
));

$builtinRows = parseCapabilityMarkdown($root.'/docs/capabilities.md', 'Builtin functions');
// Only rows from Builtin functions section (prefix match includes just that section name).
$builtinRows = array_values(array_filter(
    $builtinRows,
    static fn (array $r): bool => 'Builtin functions' === $r['section']
));

$langSummary = summarize($languageRows);
$builtinSummary = summarize($builtinRows);

$sources = [$root.'/docs/capabilities-syntax.md', $root.'/docs/capabilities.md'];
$generated = gmdate('Y-m-d', max(array_map(static fn (string $p): int => filemtime($p), $sources))).' (matrix mtime)';

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="php-compiler vs PHP reference — language and stdlib capability comparison.">
  <title>PHP capability comparison — php-compiler</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><ellipse cx='50' cy='55' rx='42' ry='32' fill='%23777BB4'/><ellipse cx='50' cy='48' rx='38' ry='28' fill='%234F5B93'/><circle cx='35' cy='42' r='4' fill='%231a1a2e'/><circle cx='65' cy='42' r='4' fill='%231a1a2e'/></svg>">
  <style>
    .cap-summary { display: grid; gap: 1rem; margin: 1.25rem 0 1.5rem; }
    @media (min-width: 640px) { .cap-summary { grid-template-columns: repeat(3, 1fr); } }
    .cap-summary article { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem; }
    .cap-summary strong { font-size: 1.4rem; color: var(--php-accent); }
    .cap-toolbar { display: flex; flex-wrap: wrap; gap: 0.75rem 1.25rem; align-items: center; margin: 1rem 0; }
    .cap-toolbar input[type="search"] { flex: 1; min-width: 12rem; padding: 0.45rem 0.65rem; border-radius: 8px; border: 1px solid var(--border); background: rgba(0,0,0,0.25); color: var(--text); }
    .cap-toolbar label { color: var(--text-muted); font-size: 0.92rem; }
    .capability-compare code { font-size: 0.85em; word-break: break-word; }
    .capability-table-wrap { max-height: 28rem; overflow: auto; margin-bottom: 2rem; }
    tr[data-support="gap"] { background: rgba(224, 108, 117, 0.06); }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a class="logo" href="index.html">
        <svg viewBox="0 0 48 48" aria-hidden="true" fill="none" xmlns="http://www.w3.org/2000/svg">
          <ellipse cx="24" cy="28" rx="20" ry="14" fill="#777BB4"/>
          <ellipse cx="24" cy="24" rx="18" ry="12" fill="#4F5B93"/>
          <circle cx="16" cy="22" r="2.5" fill="#0f0f1a"/>
          <circle cx="32" cy="22" r="2.5" fill="#0f0f1a"/>
        </svg>
        php-compiler
      </a>
      <nav class="nav" aria-label="Primary">
        <a href="index.html">Overview</a>
        <a href="development-status.html">Status</a>
        <a href="missing-implementation.html">Gaps</a>
        <a href="capability-comparison.html" aria-current="page">PHP compare</a>
        <a href="https://github.com/PurHur/php-compiler">GitHub</a>
      </nav>
    </div>
  </header>

  <main class="status-page">
    <article class="status-prose">
      <header class="status-prose__head">
        <h1>PHP capability comparison</h1>
        <p class="section-intro" style="margin-top: 0.5rem">
          How tracked <strong>PHP language</strong> and <strong>stdlib</strong> features (reference: php-src / Zend PHP)
          compare to <strong>php-compiler</strong> on VM, JIT, and AOT.
          This is <em>not</em> a full php-src inventory — only items we measure in
          <a href="https://github.com/PurHur/php-compiler/blob/master/docs/capabilities-syntax.md"><code>capabilities-syntax.md</code></a>
          and <a href="https://github.com/PurHur/php-compiler/blob/master/docs/capabilities.md"><code>capabilities.md</code></a>.
          Generated {$generated}.
        </p>
      </header>

      <div class="cap-summary">
        <article>
          <div>Language constructs</div>
          <strong>{$langSummary['full']}</strong> / {$langSummary['total']} full (VM+JIT+AOT)
          <p style="margin:0.35rem 0 0;font-size:0.88rem;color:var(--text-muted)">{$langSummary['partial']} partial · {$langSummary['gap']} gaps</p>
        </article>
        <article>
          <div>Stdlib builtins</div>
          <strong>{$builtinSummary['full']}</strong> / {$builtinSummary['total']} full (VM+JIT+AOT)
          <p style="margin:0.35rem 0 0;font-size:0.88rem;color:var(--text-muted)">{$builtinSummary['partial']} partial · {$builtinSummary['gap']} gaps</p>
        </article>
        <article>
          <div>Legend</div>
          <p style="margin:0.35rem 0 0;font-size:0.88rem;color:var(--text-muted)">
            <span class="status-yes">yes</span> supported ·
            <span class="status-wip">partial</span> subset ·
            <span class="status-no">no</span> not yet ·
            PHP column = reference has the feature
          </p>
        </article>
      </div>

      <div class="cap-toolbar">
        <input type="search" id="cap-filter" placeholder="Filter by name…" aria-label="Filter tables">
        <label><input type="checkbox" id="cap-gaps-only"> Show gaps only (no VM)</label>
      </div>

      <section>
        <h2>Language constructs</h2>
        <p>Classes, control flow, types, and related syntax from the language matrix.</p>

HTML;

$html .= renderTable($languageRows, 'language');

$html .= <<<HTML
      </section>

      <section>
        <h2>Stdlib builtins</h2>
        <p>Internal functions registered in <code>ext/standard</code> and <code>ext/types</code>.</p>

HTML;

$html .= renderTable($builtinRows, 'builtin');

$html .= <<<HTML
      </section>

      <p style="margin-top: 2rem">
        <a href="missing-implementation.html">Self-host gaps</a> ·
        <a href="development-status.html">Development status</a> ·
        Regenerate: <code>php script/generate-pages-capability-comparison.php</code>
      </p>
    </article>
  </main>

  <footer class="site-footer">
    <p><a href="https://github.com/PurHur/php-compiler">PurHur/php-compiler</a> · MIT</p>
  </footer>
  <script>
    (function () {
      var filter = document.getElementById('cap-filter');
      var gapsOnly = document.getElementById('cap-gaps-only');
      function apply() {
        var q = (filter.value || '').toLowerCase();
        var gaps = gapsOnly.checked;
        document.querySelectorAll('.capability-compare tbody tr').forEach(function (tr) {
          var name = (tr.cells[0] && tr.cells[0].textContent || '').toLowerCase();
          var isGap = tr.getAttribute('data-support') === 'gap';
          var show = name.indexOf(q) !== -1 && (!gaps || isGap);
          tr.style.display = show ? '' : 'none';
        });
      }
      filter.addEventListener('input', apply);
      gapsOnly.addEventListener('change', apply);
    })();
  </script>
</body>
</html>

HTML;

if ($check) {
    $existing = is_readable($outFile) ? (string) file_get_contents($outFile) : '';
    if ($existing !== $html) {
        fwrite(STDERR, "capability-comparison.html is stale; run: php script/generate-pages-capability-comparison.php\n");
        exit(1);
    }
    fwrite(STDOUT, "capability-comparison.html OK\n");
    exit(0);
}

file_put_contents($outFile, $html);
fwrite(STDOUT, "Wrote {$outFile} (language {$langSummary['total']}, builtins {$builtinSummary['total']})\n");
