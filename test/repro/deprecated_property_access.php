<?php

declare(strict_types=1);

ini_set('error_reporting', '32767');

class C
{
    #[\Deprecated(message: 'old prop', since: '8.4')]
    public int $x = 1;
}

$c = new C();
$c->x;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "read\n" : "no-read\n";

$c->x = 2;
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "write\n" : "no-write\n";

echo $c->x, "\n";
