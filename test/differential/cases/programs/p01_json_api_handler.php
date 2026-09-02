<?php
// #36221 program: decode JSON request → validate → sort → encode response
$body = '{"user":"ada","items":[{"sku":"b","qty":2},{"sku":"a","qty":1},{"sku":"c","qty":0}],"flags":[true,false]}';
$req = json_decode($body, true);
if (!is_array($req) || !isset($req['user'], $req['items']) || !is_array($req['items'])) {
    echo json_encode(['ok' => false, 'err' => 'bad_request']), "\n";
    exit(0);
}
$items = [];
foreach ($req['items'] as $row) {
    if (!is_array($row) || !isset($row['sku'], $row['qty'])) {
        continue;
    }
    $qty = (int) $row['qty'];
    if ($qty <= 0) {
        continue;
    }
    $items[] = ['sku' => (string) $row['sku'], 'qty' => $qty];
}
usort($items, static function ($a, $b) {
    return strcmp($a['sku'], $b['sku']);
});
$total = 0;
foreach ($items as $row) {
    $total += $row['qty'];
}
$outArr = [
    'ok' => true,
    'user' => (string) $req['user'],
    'count' => count($items),
    'total_qty' => $total,
    'items' => $items,
    'flags' => $req['flags'] ?? [],
];
$json = json_encode($outArr);
echo $json, "\n";
echo 'checksum=', strlen($json), ':', sprintf('%u', crc32($json)), "\n";
