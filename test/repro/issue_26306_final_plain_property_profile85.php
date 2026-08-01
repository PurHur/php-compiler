<?php
/**
 * Repro #26306 — final plain properties under PROFILE=8.5 (php-src-strict).
 * Expect: isFinal=1 and Fatal on child override (exit 255).
 */
class A
{
    public final string $x = 'a';
}
$rp = new ReflectionProperty(A::class, 'x');
echo 'isFinal=', $rp->isFinal() ? '1' : '0', "\n";
eval('class B extends A { public string $x = "b"; }');
echo "override_ok\n";
