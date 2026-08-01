--TEST--
ReflectionParameter: implicit nullable string $s = null is ?string / allowsNull (#26469)
--FILE--
<?php
declare(strict_types=1);

function g(string $s = null): ?string
{
    return $s;
}

function h(?string $s = null): ?string
{
    return $s;
}

function i(string $s = 'x'): string
{
    return $s;
}

function sensitive(#[\SensitiveParameter] string $password = null): void
{
}

class C
{
    public function m(string $s = null): void
    {
    }
}

foreach (['g', 'h', 'i', 'sensitive'] as $fn) {
    $p = (new ReflectionFunction($fn))->getParameters()[0];
    echo $fn,
        ' type=', (string) $p->getType(),
        ' allowsNull=', $p->allowsNull() ? '1' : '0';
    $t = $p->getType();
    if ($t instanceof ReflectionNamedType) {
        echo ' namedAllowsNull=', $t->allowsNull() ? '1' : '0',
            ' name=', $t->getName();
    }
    echo "\n";
}

$mp = (new ReflectionMethod(C::class, 'm'))->getParameters()[0];
echo 'method type=', (string) $mp->getType(),
    ' allowsNull=', $mp->allowsNull() ? '1' : '0';
$mt = $mp->getType();
if ($mt instanceof ReflectionNamedType) {
    echo ' namedAllowsNull=', $mt->allowsNull() ? '1' : '0',
        ' name=', $mt->getName();
}
echo "\n";
?>
--EXPECT--
g type=?string allowsNull=1 namedAllowsNull=1 name=string
h type=?string allowsNull=1 namedAllowsNull=1 name=string
i type=string allowsNull=0 namedAllowsNull=0 name=string
sensitive type=?string allowsNull=1 namedAllowsNull=1 name=string
method type=?string allowsNull=1 namedAllowsNull=1 name=string
