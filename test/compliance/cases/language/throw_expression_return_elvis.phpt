--TEST--
Language: throw expressions — return ?? and elvis ?: (#9209, Zend zend_compile.c)
--FILE--
<?php
function f(?int $x): int {
    return $x ?? throw new RuntimeException('x');
}

try {
    var_dump(f(null));
} catch (RuntimeException $e) {
    echo 'caught:', $e->getMessage(), "\n";
}

try {
    $y = 0 ?: throw new RuntimeException('y');
    var_dump($y);
} catch (RuntimeException $e) {
    echo 'caught:', $e->getMessage(), "\n";
}
?>
--EXPECT--
caught:x
caught:y
