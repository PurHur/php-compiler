<?php

declare(strict_types=1);

/**
 * ReflectionAttribute / NamedType / ClassConstant / Property excess argc (#30896).
 *
 * php-src: ext/reflection/php_reflection.c
 */
#[Attribute]
class Issue30896AttrA
{
    public function __construct(public int $x = 1)
    {
    }
}

#[Issue30896AttrA(2)]
class Issue30896AttrTarget
{
}

class Issue30896PropTmp
{
    public int $x = 1;
    public int $y;
}

$a = (new ReflectionClass(Issue30896AttrTarget::class))->getAttributes()[0];
$t = (new ReflectionFunction('strlen'))->getParameters()[0]->getType();
$u = (new ReflectionFunction(static function (int|string $x) {}))->getParameters()[0]->getType();
$c = new ReflectionClassConstant(DateTime::class, 'ATOM');
$p = new ReflectionProperty(Issue30896PropTmp::class, 'x');
$o = new Issue30896PropTmp();
foreach ([
    'attr.getName' => fn () => $a->getName(1),
    'attr.getArguments' => fn () => $a->getArguments(1)[0],
    'attr.newInstance' => fn () => get_class($a->newInstance(1)),
    'named.getName' => fn () => $t->getName(1),
    'named.isBuiltin' => fn () => $t->isBuiltin(1),
    'type.allowsNull' => fn () => $t->allowsNull(1),
    'union.getTypes' => fn () => $u->getTypes(1),
    'cc.getName' => fn () => $c->getName(1),
    'cc.getValue' => fn () => $c->getValue(1),
    'prop.getName' => fn () => $p->getName(1),
    'prop.getValue' => fn () => $p->getValue($o, 1),
    'prop.isInitialized' => fn () => $p->isInitialized($o, 1),
] as $label => $fn) {
    try {
        $fn();
        echo "$label ACCEPTED\n";
    } catch (Throwable $e) {
        echo "$label ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok=', $a->getName(), ',', $t->getName(), ',', $c->getName(), ',', $p->getName(), ',', $p->getValue($o), "\n";
