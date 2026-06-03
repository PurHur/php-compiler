--TEST--
Closure-local static counters are per closure instance (issue #4872, Zend/zend_closures.c)
--FILE--
<?php
$f = function (): int {
    static $n = 0;
    return ++$n;
};
echo $f(), " ", $f(), "\n";
$g = function (): int {
    static $n = 0;
    return ++$n;
};
echo $g(), " ", $g(), "\n";
for ($i = 0; $i < 2; $i++) {
    $h = function (): int {
        static $n = 0;
        return ++$n;
    };
    echo $h(), " ";
}
echo "\n";
--EXPECT--
1 2
1 2
1 1 
