<?php
/**
 * Issue #25457 (re-#25428 / #25379) — final plain properties on the default
 * reference profile must compile-fatal like Zend 8.2 (Zend/zend_compile.c).
 *
 * Behavioral gate: if VM ever prints "compiled" / "write=", the profile reject
 * is broken (prior closes green-washed this with stamp-only checks).
 *
 *   php bin/vm.php test/repro/maintainer_gap_final_plain_property_refprofile_re25428.php
 *   # expect exit 255 + Cannot declare property C::$x final...
 *   # never: compiled / write=
 *
 * PROFILE=8.4 allows plain finals; child override must Fatal (not parseAndCompile).
 * See maintainer_gap_final_property_profile84.php / final_plain_property_override_84.phpt.
 */
class C
{
    public final string $x = 'a';
}
echo "compiled\n";
$o = new C();
$o->x = 'z';
echo 'write=', $o->x, "\n";
