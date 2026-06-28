<?php

declare(strict_types=1);

if (!method_exists(SplFileObject::class, 'rewind')) {
    echo "fail: rewind() missing\n";
    exit(0);
}
if (!method_exists(SplFileObject::class, 'key')) {
    echo "fail: key() missing\n";
    exit(0);
}
if (!method_exists(SplFileObject::class, 'valid')) {
    echo "fail: valid() missing\n";
    exit(0);
}
if (!method_exists(SplFileObject::class, 'eof')) {
    echo "fail: eof() missing\n";
    exit(0);
}

$f = new SplTempFileObject();
$f->fwrite("a\nb\n");
$f->rewind();
$lines = [];
foreach ($f as $k => $line) {
    $lines[$k] = $line;
}
if ($lines !== [0 => "a\n", 1 => "b\n"]) {
    echo 'fail: foreach '.var_export($lines, true)."\n";
    exit(0);
}
echo "ok\n";
