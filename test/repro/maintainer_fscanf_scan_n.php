<?php

declare(strict_types=1);

$fail = 0;

$n = 0;
$r = sscanf('abc 42', '%s %d %n', $a, $b, $n);
if (3 !== $r || 'abc' !== $a || 42 !== $b || 6 !== $n) {
    echo "FAIL sscanf by-ref: r=$r a=$a b=$b n=$n\n";
    ++$fail;
}

$parsed = sscanf('abc 42', '%s %d %n');
if (!\is_array($parsed) || 3 !== \count($parsed) || 'abc' !== $parsed[0] || 42 !== $parsed[1] || 6 !== $parsed[2]) {
    echo "FAIL sscanf two-arg\n";
    var_export($parsed);
    echo "\n";
    ++$fail;
}

$n2 = 0;
$r2 = sscanf('abc', '%n', $n2);
if (1 !== $r2 || 0 !== $n2) {
    echo "FAIL sscanf leading %n: r=$r2 n=$n2\n";
    ++$fail;
}

$s = '';
$n3 = 0;
$r3 = sscanf('hello', '%2s%n', $s, $n3);
if (2 !== $r3 || 'he' !== $s || 2 !== $n3) {
    echo "FAIL sscanf %2s%n: r=$r3 s=$s n=$n3\n";
    ++$fail;
}

$f = fopen('php://memory', 'r+');
if (false === $f) {
    echo "FAIL fopen\n";
    exit(1);
}
fwrite($f, '42 7');
rewind($f);
$n4 = 0;
$r4 = fscanf($f, '%d %d %n', $fa, $fb, $n4);
fclose($f);
if (3 !== $r4 || 42 !== $fa || 7 !== $fb || 4 !== $n4) {
    echo "FAIL fscanf: r=$r4 fa=$fa fb=$fb n=$n4\n";
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
