<?php

declare(strict_types=1);

$a = unserialize('a:2:{i:0;i:1;i:1;R:2;}');
if (!\is_array($a)) {
    echo 'fail: not array';
    exit(1);
}
if (2 !== \count($a)) {
    echo 'fail: count='.\count($a);
    exit(1);
}
if ($a[0] !== $a[1]) {
    echo 'fail: values differ';
    exit(1);
}
if ($a[0] !== 1) {
    echo 'fail: value='.$a[0];
    exit(1);
}
$a[0] = 5;
if (5 !== $a[1]) {
    echo 'fail: alias broken after mutate';
    exit(1);
}
echo 'ok';
