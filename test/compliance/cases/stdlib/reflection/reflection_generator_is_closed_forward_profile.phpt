--TEST--
ReflectionGenerator::isClosed() on 8.4 forward profile (#22242, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

function gen() {
    yield 1;
}

echo method_exists(ReflectionGenerator::class, 'isClosed') ? "has_isClosed\n" : "missing_isClosed\n";

$g = gen();
$r = new ReflectionGenerator($g);
echo 'fresh=', $r->isClosed() ? 'T' : 'F', "\n";
$g->current();
echo 'yielded=', $r->isClosed() ? 'T' : 'F', "\n";
$g->next();
echo 'exhausted=', $r->isClosed() ? 'T' : 'F', "\n";
--EXPECT--
has_isClosed
fresh=F
yielded=F
exhausted=T
