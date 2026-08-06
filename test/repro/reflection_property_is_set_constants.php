<?php
// Issue #28137 — ReflectionProperty::IS_*_SET + getModifiers set bits (PROFILE≥8.4).
class C
{
    public private(set) string $name = 'n';
}
$r = new ReflectionProperty(C::class, 'name');
if (!$r->isPrivateSet()) {
    fwrite(STDERR, "fail: isPrivateSet\n");
    exit(1);
}
foreach (['IS_PRIVATE_SET' => 4096, 'IS_PROTECTED_SET' => 2048, 'IS_PUBLIC_SET' => 1024] as $c => $want) {
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
$mods = $r->getModifiers();
if (($mods & ReflectionProperty::IS_PRIVATE_SET) === 0) {
    fwrite(STDERR, "fail: getModifiers missing IS_PRIVATE_SET mods=$mods\n");
    exit(1);
}
if (($mods & ReflectionProperty::IS_FINAL) === 0) {
    fwrite(STDERR, "fail: getModifiers missing IS_FINAL mods=$mods\n");
    exit(1);
}
echo "ok mods=$mods\n";
