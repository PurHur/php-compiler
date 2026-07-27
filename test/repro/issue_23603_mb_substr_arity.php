<?php

declare(strict_types=1);

$r = new ReflectionFunction('mb_substr');
echo $r->getNumberOfParameters(), "\n";
try {
    echo mb_substr('abcdef', 0, 2, 'UTF-8', true), "\n";
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
