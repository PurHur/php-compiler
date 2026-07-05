--TEST--
ReflectionGenerator — generator introspection (#5964, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

function gen(): Generator {
    yield 1;
}

$g = gen();
$g->rewind();
$ref = new ReflectionGenerator($g);
echo $ref->getFunction()->getName(), "\n";
echo ($ref->getExecutingLine() > 0) ? "line_ok\n" : "line_bad\n";
echo is_string($ref->getExecutingFile()) ? "file_ok\n" : "file_bad\n";
echo ($ref->getExecutingGenerator() === $g) ? "same_ok\n" : "same_bad\n";
echo class_exists('ReflectionGenerator') ? "class_ok\n" : "class_bad\n";
--EXPECT--
gen
line_ok
file_ok
same_ok
class_ok
