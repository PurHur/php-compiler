<?php
/**
 * Repro #26339 (re-#26306) — final plain properties under PROFILE=8.4 (php-src-strict).
 *
 * Expect: isFinal=1 and Fatal on child override via eval (exit 255).
 * Same matrix as issue_26306 (PROFILE=8.5); locks the issue-body script so a
 * tests-only "already green" close cannot silently drift again.
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_26339_final_plain_property_profile84.php
 */
class A
{
    public final string $x = 'a';
}
$rp = new ReflectionProperty(A::class, 'x');
echo 'isFinal=', $rp->isFinal() ? '1' : '0', "\n";
eval('class B extends A { public string $x = "b"; }');
echo "override_ok\n";
