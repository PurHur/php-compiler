--TEST--
ReflectionGenerator::getThis()/getTrace() — live generator introspection (#22067, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

class G {
    public function gen(): Generator {
        yield $this;
        yield 2;
    }
}

function static_gen(): Generator {
    yield 1;
}

$g = (new G())->gen();
$g->current();
$ref = new ReflectionGenerator($g);
echo method_exists($ref, 'getThis') ? "has_getThis\n" : "missing_getThis\n";
echo method_exists($ref, 'getTrace') ? "has_getTrace\n" : "missing_getTrace\n";
$t = $ref->getThis();
echo is_object($t) ? get_class($t) : var_export($t, true), "\n";
$tr = $ref->getTrace();
echo count($tr), "\n";
echo $tr[0]['function'] ?? '?', "\n";

$g2 = static_gen();
$g2->current();
$ref2 = new ReflectionGenerator($g2);
echo var_export($ref2->getThis(), true), "\n";
$tr2 = $ref2->getTrace();
echo ($tr2[0]['function'] ?? '?'), "\n";
--EXPECT--
has_getThis
has_getTrace
G
1
gen
NULL
static_gen
