<?php
// #36221 program: string template rendering with escapes + sprintf
function h(string $s): string {
    return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $s);
}
function render(string $tpl, array $vars): string {
    $out = $tpl;
    foreach ($vars as $k => $v) {
        $out = str_replace('{{' . $k . '}}', h((string) $v), $out);
    }
    return $out;
}
$users = [
    ['name' => 'Ada <Lovelace>', 'score' => 98.5],
    ['name' => 'Grace & Hopper', 'score' => 100],
    ['name' => 'Alan "Turing"', 'score' => 91.25],
];
$n = count($users);
$parts = [];
$sum = 0.0;
foreach ($users as $i => $u) {
    $line = render('<li id="u{{i}}">{{name}} — {{score}}</li>', [
        'i' => $i,
        'name' => $u['name'],
        'score' => sprintf('%.2f', $u['score']),
    ]);
    $parts[] = $line;
    $sum += $u['score'];
}
$html = "<ul>\n" . implode("\n", $parts) . "\n</ul>\n";
$avg = $sum / $n;
$summary = sprintf('n=%d avg=%.3f', $n, $avg);
echo $html;
echo $summary, "\n";
echo 'checksum=', strlen($html), ':', sprintf('%u', crc32($html . $summary)), "\n";
