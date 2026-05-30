--TEST--
Language: throw expressions — ternary, ??, && (PHP 8.0, Zend zend_compile.c #3802)
--FILE--
<?php
// ternary false arm
try {
    echo (false ? 1 : throw new LogicException('ternary')), "\n";
} catch (LogicException $e) {
    echo 'caught:', $e->getMessage(), "\n";
}

// null coalesce RHS
$missing = null;
try {
    echo ($missing ?? throw new LogicException('coalesce')), "\n";
} catch (LogicException $e) {
    echo 'caught:', $e->getMessage(), "\n";
}

// short-circuit && RHS when LHS is true
try {
    echo (true && throw new LogicException('and')), "\n";
} catch (LogicException $e) {
    echo 'caught:', $e->getMessage(), "\n";
}

// short-circuit && skips throw when LHS is false
$hit = 0;
try {
    echo (false && throw new LogicException('skip')), "\n";
} catch (LogicException $e) {
    $hit = 1;
}
echo $hit, "\n";
?>
--EXPECT--
caught:ternary
caught:coalesce
caught:and
0
