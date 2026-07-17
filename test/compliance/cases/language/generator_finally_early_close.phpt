--TEST--
Generator try/finally runs on early close — foreach break / unset (Zend/zend_generators.c, #19905)
--FILE--
<?php
declare(strict_types=1);

function gen_break() {
    try {
        yield 1;
        yield 2;
    } finally {
        echo "FIN_BREAK\n";
    }
}
$gb = gen_break();
foreach ($gb as $v) {
    echo "V=$v\n";
    break;
}
unset($gb);

function gen_unset() {
    try {
        yield 1;
        yield 2;
    } finally {
        echo "FIN_UNSET\n";
    }
}
$g = gen_unset();
$g->current();
unset($g);

function gen_throw() {
    try {
        yield 1;
        throw new Exception('x');
    } finally {
        echo "FIN_THROW\n";
    }
}
try {
    foreach (gen_throw() as $v) {
        echo "V=$v\n";
    }
} catch (Exception $e) {
    echo "caught\n";
}
--EXPECT--
V=1
FIN_BREAK
FIN_UNSET
V=1
FIN_THROW
caught
