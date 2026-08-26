<?php
declare(strict_types=1);

/**
 * #35178 — AOT Generator::send into yield-receive then echo the local (Module.php:180).
 * php-src: Zend/zend_generators.c — zend_generator_send / zend_generator_resume
 */
function g()
{
    $x = yield;
    echo 'got=', $x, "\n";
}

$g = g();
$g->send('hi');

function g2()
{
    $x = yield 0;
    echo $x;
}

$g2 = g2();
$g2->current();
$g2->send('z');
echo "\n";
