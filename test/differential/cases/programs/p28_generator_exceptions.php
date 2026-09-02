<?php
// #36221 program: generator throws mid-stream; consumer catches
function stream(): \Generator {
    yield 'a';
    yield 'b';
    throw new RuntimeException('mid');
    yield 'c';
}
$got = [];
$err = 'none';
try {
    foreach (stream() as $v) {
        $got[] = $v;
    }
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
$out = implode(',', $got) . '|err=' . $err . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
