<?php

declare(strict_types=1);

function gen(): Generator
{
    yield 1;

    return 99;
}

$g = gen();
$g->next();
$g->next();
echo 'valid='.var_export($g->valid(), true)."\n";
echo 'ret='.$g->getReturn()."\n";
try {
    $g->next();
    echo "next_ok\n";
} catch (Throwable $e) {
    echo 'fail: '.get_class($e).': '.$e->getMessage()."\n";
}
try {
    foreach ($g as $v) {
        echo $v;
    }
    echo "foreach_ok\n";
} catch (Throwable $e) {
    echo 'foreach '.get_class($e).': '.$e->getMessage()."\n";
}
