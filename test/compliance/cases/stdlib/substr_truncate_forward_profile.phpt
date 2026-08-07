--TEST--
stdlib substr() rejects phantom $truncate on PROFILE=8.4 — php-src arity 3 (#27749, re-#17239)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionFunction('substr');
echo 'argc=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), "\n";
}

try {
    echo substr('abcdef', 0, 3, true), "\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    echo substr(string: 'abcdef', offset: 0, length: 3, truncate: true), "\n";
} catch (ArgumentCountError|Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo substr('abcdef', 0, 3), "\n";
?>
--EXPECT--
argc=3
string
offset
length
substr() expects at most 3 arguments, 4 given
Error: Unknown named parameter $truncate
abc
