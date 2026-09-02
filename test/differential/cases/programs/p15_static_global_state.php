<?php
// #36221 program: static locals + globals + class statics
function bump(): int {
    static $n = 0;
    $n++;
    return $n;
}
class Box {
    public static int $hits = 0;
    public static function hit(): int {
        self::$hits++;
        return self::$hits;
    }
}
$GLOBALS['g'] = 'seed';
function touchGlobal(string $s): string {
    $GLOBALS['g'] .= '|' . $s;
    return $GLOBALS['g'];
}
$lines = [
    'b1=' . bump(),
    'b2=' . bump(),
    'h1=' . Box::hit(),
    'h2=' . Box::hit(),
    'g1=' . touchGlobal('a'),
    'g2=' . touchGlobal('b'),
    'hits=' . Box::$hits,
];
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
