<?php
/**
 * Issue #19087 — SplFileObject DROP_NEW_LINE|SKIP_EMPTY iteration (ext/spl/spl_file_object.c).
 */
$path = sys_get_temp_dir() . '/phpc_spl_drop_' . getmypid() . '.txt';
$p = $path;
file_put_contents($p, "a\nb\n\n");

$f = new SplFileObject($p);
$f->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
$lines = [];
foreach ($f as $line) {
    $lines[] = $line;
}

@unlink($p);

if ($lines === ['a', 'b', false]) {
    echo "ok\n";
    exit(0);
}
echo 'fail: ' . json_encode($lines) . "\n";
exit(1);
