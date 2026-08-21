<?php
// AOT: SplFileObject::SKIP_EMPTY ignored on foreach — Zend/VM skip blank lines.
$path = sys_get_temp_dir() . '/phpc_splfo_skip_empty_' . getmypid() . '.txt';
file_put_contents($path, "a\n\nb\n");
$f = new SplFileObject($path);
$f->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
$parts = [];
foreach ($f as $line) {
    $parts[] = '[' . $line . ']';
}
echo implode('|', $parts), "\n";
@unlink($path);
