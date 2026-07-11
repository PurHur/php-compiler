--TEST--
stdlib fputcsv() — backslash escape char does not double field backslashes (#15383, ext/standard/file.c)
--FILE--
<?php
foreach ([
    ['a\b', '\\', "\"a\\b\"\n"],
    ['a"b', '\\', "\"a\"\"b\"\n"],
    ['x!y', '!', "\"x!y\"\n"],
] as [$field, $escape, $expected]) {
    $fp = fopen('php://memory', 'r+');
    fputcsv($fp, [$field], ',', '"', $escape);
    rewind($fp);
    $line = stream_get_contents($fp);
    fclose($fp);
    echo $line === $expected ? 'ok' : 'fail', "\n";
}
?>
--EXPECT--
ok
ok
ok
