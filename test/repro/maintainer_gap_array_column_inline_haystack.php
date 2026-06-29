<?php

declare(strict_types=1);

$r = array_column([['n' => 'a'], ['n' => 'b']], 'n');
var_export($r);
echo "\n";
if ($r !== ['a', 'b']) {
    exit(1);
}
echo "ok\n";
