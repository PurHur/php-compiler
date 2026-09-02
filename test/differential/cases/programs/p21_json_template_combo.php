<?php
// #36221 program: JSON decode → template → JSON encode (interaction)
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
$payload = json_encode([
    'title' => 'Hello <World>',
    'items' => ['one', 'two & three'],
    'meta' => ['n' => 2, 'ok' => true],
], JSON_UNESCAPED_UNICODE);
$data = json_decode($payload, true);
$lis = [];
foreach ($data['items'] as $it) {
    $lis[] = '<li>' . h($it) . '</li>';
}
$html = '<h1>' . h($data['title']) . '</h1><ul>' . implode('', $lis) . '</ul>';
$resp = json_encode(['html' => $html, 'n' => $data['meta']['n'], 'ok' => $data['meta']['ok']]);
echo $resp, "\n";
echo 'checksum=', strlen($resp), ':', sprintf('%u', crc32($resp)), "\n";
