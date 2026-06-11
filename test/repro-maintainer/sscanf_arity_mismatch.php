<?php

declare(strict_types=1);

$a = 0;
try {
    sscanf('42 foo', '%d %s', $a);
    echo "no error (bug)\n";
} catch (ValueError $e) {
    echo 'arity: ', $e->getMessage(), "\n";
}

$b = 0;
$c = 0;
try {
    sscanf('42', '%d', $b, $c);
    echo "no error (bug)\n";
} catch (ValueError $e) {
    echo 'extra: ', $e->getMessage(), "\n";
}
