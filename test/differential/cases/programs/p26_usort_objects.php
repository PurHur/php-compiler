<?php
// #36221 program: usort objects + spaceship + keys
class Row {
    public string $k;
    public int $v;
    public function __construct(string $k, int $v) {
        $this->k = $k;
        $this->v = $v;
    }
}
$rows = [
    new Row('b', 2),
    new Row('a', 2),
    new Row('c', 1),
    new Row('a', 1),
    new Row('b', 1),
];
usort($rows, static function (Row $x, Row $y): int {
    $c = $x->v <=> $y->v;
    return $c !== 0 ? $c : strcmp($x->k, $y->k);
});
$lines = [];
foreach ($rows as $r) {
    $lines[] = $r->k . ':' . $r->v;
}
$out = implode(',', $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
