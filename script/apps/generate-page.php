<?php

declare(strict_types=1);

/**
 * Generate docs/pages/apps.html from test/apps/SCOREBOARD.json (#36380).
 */

$root = dirname(__DIR__, 2);
$scoreboardPath = $root . '/test/apps/SCOREBOARD.json';
$manifestPath = $root . '/test/apps/MANIFEST.json';
$outPath = $root . '/docs/pages/apps.html';

$scoreboard = is_file($scoreboardPath)
    ? json_decode((string) file_get_contents($scoreboardPath), true)
    : null;
$manifest = json_decode((string) file_get_contents($manifestPath), true);

$rows = '';
$packages = is_array($scoreboard) && isset($scoreboard['packages']) ? $scoreboard['packages'] : [];
if ($packages === [] && is_array($manifest) && isset($manifest['packages'])) {
    $packages = $manifest['packages'];
}

foreach ($packages as $pkg) {
    $slug = htmlspecialchars((string) ($pkg['slug'] ?? ''), ENT_QUOTES);
    $composer = htmlspecialchars((string) ($pkg['composer'] ?? $slug), ENT_QUOTES);
    $sha = htmlspecialchars((string) ($pkg['sha'] ?? '—'), ENT_QUOTES);
    $status = htmlspecialchars((string) ($pkg['status'] ?? 'pending'), ENT_QUOTES);
    $zend = fmtBackend($pkg['zend'] ?? null);
    $vm = fmtBackend($pkg['vm'] ?? null);
    $aot = fmtBackend($pkg['aot'] ?? null);
    $rate = $pkg['aot_pass_rate'] ?? null;
    $rateS = $rate === null ? '—' : htmlspecialchars(sprintf('%.1f%%', $rate), ENT_QUOTES);
    $blockers = htmlspecialchars(implode('; ', $pkg['blockers'] ?? []), ENT_QUOTES);
    $rows .= "<tr><td>{$composer}</td><td><code>{$sha}</code></td><td>{$status}</td>"
        . "<td>{$zend}</td><td>{$vm}</td><td>{$aot}</td><td>{$rateS}</td>"
        . "<td class=\"blockers\">{$blockers}</td></tr>\n";
}

$generated = htmlspecialchars((string) ($scoreboard['generated_at'] ?? gmdate('Y-m-d\TH:i:s\Z')), ENT_QUOTES);
$count = count($packages);

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>php-compiler — real-world apps scoreboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="css/style.css">
<style>
  table.apps { border-collapse: collapse; width: 100%; font-size: 0.92rem; }
  table.apps th, table.apps td { border: 1px solid #ccc; padding: 0.4rem 0.55rem; text-align: left; vertical-align: top; }
  table.apps th { background: #f4f4f4; }
  td.blockers { max-width: 28rem; word-break: break-word; color: #666; }
  code { font-size: 0.85em; }
</style>
</head>
<body>
<main>
  <h1>Real-world application corpus</h1>
  <p>Child of <a href="https://github.com/PurHur/php-compiler/issues/36380">#36380</a>.
  Each package runs its own fixtures under Zend, VM, and AOT. Failures become
  differential reductions — never app-specific patches.</p>
  <p><strong>{$count}</strong> packages · generated <code>{$generated}</code> ·
  refresh with <code>make apps-scoreboard</code>.</p>
  <table class="apps">
    <thead>
      <tr>
        <th>Package</th><th>SHA</th><th>Status</th>
        <th>Zend</th><th>VM</th><th>AOT</th><th>AOT %</th><th>Blockers</th>
      </tr>
    </thead>
    <tbody>
{$rows}    </tbody>
  </table>
</main>
</body>
</html>
HTML;

if (false === file_put_contents($outPath, $html)) {
    fwrite(STDERR, "generate-apps-page: failed to write $outPath\n");
    exit(1);
}
echo "generate-apps-page: wrote $outPath\n";

function fmtBackend(?array $b): string
{
    if ($b === null || ($b['status'] ?? '') === 'pending') {
        return '—';
    }
    $status = htmlspecialchars((string) $b['status'], ENT_QUOTES);
    $pass = (int) ($b['pass'] ?? 0);
    $fail = (int) ($b['fail'] ?? 0);
    return "{$status} {$pass}/" . ($pass + $fail);
}
