<?php

declare(strict_types=1);

function counter(): int
{
    static $n = 0;
    $n++;

    return $n;
}

counter();

$rf = new ReflectionFunction('counter');
if (!method_exists($rf, 'getStaticVariables')) {
    fwrite(STDERR, "missing_method\n");
    exit(1);
}

$sv = $rf->getStaticVariables();
echo 'n='.$sv['n']."\n";

if (1 !== $sv['n']) {
    fwrite(STDERR, 'bad_n='.$sv['n']."\n");
    exit(1);
}
