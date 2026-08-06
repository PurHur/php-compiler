<?php
// Issue #28248 — ReflectionProperty::IS_VIRTUAL / IS_ABSTRACT + getModifiers bits (PROFILE≥8.4).
class C
{
    public string $name {
        get => 'x';
        set(string $v) {}
    }
}

abstract class Abs
{
    abstract public string $x { get; }
}

foreach (['IS_VIRTUAL' => 512, 'IS_ABSTRACT' => 64] as $c => $want) {
    if (!defined('ReflectionProperty::' . $c)) {
        fwrite(STDERR, "fail: undefined ReflectionProperty::$c\n");
        exit(1);
    }
    $got = constant('ReflectionProperty::' . $c);
    if ($got !== $want) {
        fwrite(STDERR, "fail: ReflectionProperty::$c=$got want=$want\n");
        exit(1);
    }
}

$r = new ReflectionProperty(C::class, 'name');
if (!$r->isVirtual()) {
    fwrite(STDERR, "fail: isVirtual\n");
    exit(1);
}
$mods = $r->getModifiers();
if (($mods & ReflectionProperty::IS_VIRTUAL) === 0) {
    fwrite(STDERR, "fail: getModifiers missing IS_VIRTUAL mods=$mods\n");
    exit(1);
}

$ra = new ReflectionProperty(Abs::class, 'x');
if (!$ra->isAbstract()) {
    fwrite(STDERR, "fail: isAbstract\n");
    exit(1);
}
$amods = $ra->getModifiers();
if (($amods & ReflectionProperty::IS_ABSTRACT) === 0) {
    fwrite(STDERR, "fail: getModifiers missing IS_ABSTRACT mods=$amods\n");
    exit(1);
}

echo "ok virtual_mods=$mods abstract_mods=$amods\n";
