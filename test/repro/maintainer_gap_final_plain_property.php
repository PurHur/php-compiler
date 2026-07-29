<?php
/**
 * Issue #24992 (re-#24686) — final plain properties on the default reference
 * profile must compile-fatal like Zend 8.2 (Zend/zend_compile.c).
 *
 *   php bin/vm.php test/repro/maintainer_gap_final_plain_property.php
 *   # expect exit 255 + Cannot declare property A::$x final...
 *   # never: compiled / override_ok / write=
 */
class A
{
    public final string $x = 'a';
}
echo "compiled\n";
class B extends A
{
    public string $x = 'b';
}
echo "override_ok\n";
$o = new A();
$o->x = 'z';
echo 'write=', $o->x, "\n";
