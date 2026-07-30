<?php
/**
 * Issue #25322 (re-#24992 / #23845 / #24770) — final plain properties on the
 * default reference profile must compile-fatal like Zend 8.2 (Zend/zend_compile.c).
 *
 * Issue table (reference profile):
 * - `public final string $x` in class body → Zend compile fatal (never "compiled")
 * - child redeclares property → n/a when parent fails first
 * - `$o->x = 'z'` after compile → n/a when parent fails first
 *
 *   php bin/vm.php test/repro/maintainer_gap_final_plain_property_refprofile.php
 *   # expect exit 255 + Cannot declare property C::$x final...
 *   # never: compiled / override_ok / write=
 *
 * PROFILE=8.4 allows plain finals; see maintainer_gap_final_property_profile84.php.
 */
class C
{
    public final string $x = 'a';
}
echo "compiled\n";
class D extends C
{
    public string $x = 'b';
}
echo "override_ok\n";
$o = new C();
$o->x = 'z';
echo 'write=', $o->x, "\n";
