<?php
// Issue #22341 / follow-up #22364 — plain final property isFinal + getModifiers IS_FINAL bit.
class C
{
    public final string $x = 'a';
}
$r = new ReflectionProperty('C', 'x');
if (!$r->isFinal()) {
    fwrite(STDERR, "fail: isFinal false\n");
    exit(1);
}
if (33 !== $r->getModifiers()) {
    fwrite(STDERR, 'fail: getModifiers=' . $r->getModifiers() . " expected 33\n");
    exit(1);
}
if (32 !== ReflectionProperty::IS_FINAL) {
    fwrite(STDERR, "fail: IS_FINAL constant\n");
    exit(1);
}
echo "ok\n";
