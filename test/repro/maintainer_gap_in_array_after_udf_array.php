<?php
declare(strict_types=1);

function hold(array $v): void
{
    json_encode($v);
}

hold(['x' => 1]);

if (!in_array('a', ['a', 'b'], true)) {
    echo "fail: in_array\n";
    exit(1);
}
if (1 !== array_search('b', ['a', 'b'], true)) {
    echo "fail: array_search\n";
    exit(1);
}
if (!array_key_exists('k', ['k' => 1])) {
    echo "fail: array_key_exists\n";
    exit(1);
}
echo "ok\n";
