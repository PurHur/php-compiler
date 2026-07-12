<?php

declare(strict_types=1);

$c = array_combine(array('a', 'b'), array(1, 2));
if ('1|2' !== $c['a'].'|'.$c['b']) {
    fwrite(STDERR, "first combine wrong\n");
    exit(1);
}
$d = array_combine(array(0, 1), array('x', 'y'));
if ('x|y' !== $d[0].'|'.$d[1]) {
    fwrite(STDERR, "second combine wrong\n");
    exit(1);
}
if (0 !== count(array_combine([], []))) {
    fwrite(STDERR, "empty combine wrong\n");
    exit(1);
}
echo "ok\n";
