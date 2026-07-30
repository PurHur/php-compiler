--TEST--
parse_str() rejects 3rd separator arg on PROFILE=8.4 — php-src arity 2 (#23949, re-#17320)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionFunction('parse_str');
echo 'argc=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), "\n";
}

$out = [];
try {
    parse_str('a=1;b=2', $out, ';');
    echo 'fail: 3-arg accepted: ', var_export($out, true), "\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    parse_str('a=1;b=2', $out, separator: ';');
    echo 'fail: named separator accepted', "\n";
} catch (ArgumentCountError|Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

parse_str('a=1;b=2', $out);
echo var_export($out, true), "\n";
--EXPECT--
argc=2
string
result
parse_str() expects exactly 2 arguments, 3 given
Error: Unknown named parameter $separator
array (
  'a' => '1;b=2',
)
