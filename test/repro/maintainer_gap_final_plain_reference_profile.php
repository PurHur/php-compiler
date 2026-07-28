<?php
/**
 * Issue #24316 (re-#24216 / #23884) — final plain properties on the default
 * reference profile must compile-fatal like Zend 8.2 (Zend/zend_compile.c).
 *
 * Covers the construct + write path from the issue table (not only declare):
 * never print "declare=ok" / never succeed a write.
 *
 *   php bin/vm.php test/repro/maintainer_gap_final_plain_reference_profile.php
 *   # expect exit 255 + Cannot declare property C::$x final...
 *
 * PROFILE=8.4 allows plain finals (sibling #24317).
 */
class C
{
    final public string $x = 'a';
}

$o = new C();
echo "declare=ok value={$o->x}\n";
$o->x = 'b';
echo "write={$o->x}\n";
