<?php

declare(strict_types=1);

$fail = 0;

function sens(#[\SensitiveParameter] string $x): void {}

$r = new ReflectionParameter('sens', 0);
if (!method_exists($r, 'isSensitiveParameter')) {
    echo "FAIL method_exists isSensitiveParameter\n";
    ++$fail;
} elseif (!$r->isSensitiveParameter()) {
    echo "FAIL isSensitiveParameter on sensitive param\n";
    ++$fail;
}

function plain(string $x): void {}
$plain = new ReflectionParameter('plain', 0);
if (method_exists($plain, 'isSensitiveParameter') && $plain->isSensitiveParameter()) {
    echo "FAIL isSensitiveParameter on plain param\n";
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
