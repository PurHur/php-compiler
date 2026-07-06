<?php

declare(strict_types=1);

$f = 6;
$r = file(__FILE__, $f);
echo \is_array($r) ? (string) \count($r) : 'false';
