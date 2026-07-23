--TEST--
ReflectionParameter::allowsNull() true for untyped (incl. variadic); typed/mixed parity (#22524)
--FILE--
<?php
declare(strict_types=1);

function untyped_variadic(...$c) {}
function typed_variadic(int ...$c) {}
function nullable_typed_variadic(?int ...$c) {}
function untyped($c) {}
function typed_int(int $c) {}
function typed_mixed(mixed $c) {}
function typed_nullable(?string $c) {}

foreach ([
    'untyped_variadic',
    'typed_variadic',
    'nullable_typed_variadic',
    'untyped',
    'typed_int',
    'typed_mixed',
    'typed_nullable',
] as $fn) {
    $p = (new ReflectionFunction($fn))->getParameters()[0];
    echo $fn,
        ' hasType=', $p->hasType() ? '1' : '0',
        ' variadic=', $p->isVariadic() ? '1' : '0',
        ' allowsNull=', $p->allowsNull() ? '1' : '0',
        "\n";
}
?>
--EXPECT--
untyped_variadic hasType=0 variadic=1 allowsNull=1
typed_variadic hasType=1 variadic=1 allowsNull=0
nullable_typed_variadic hasType=1 variadic=1 allowsNull=1
untyped hasType=0 variadic=0 allowsNull=1
typed_int hasType=1 variadic=0 allowsNull=0
typed_mixed hasType=1 variadic=0 allowsNull=1
typed_nullable hasType=1 variadic=0 allowsNull=1
