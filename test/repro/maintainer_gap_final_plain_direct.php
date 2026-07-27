<?php
/**
 * Issue #23884 (re-#23859/#23845/#23665) — final plain properties on the
 * default/reference profile must Fatal like Zend 8.2 (Zend/zend_compile.c).
 *
 * Direct class body (not only eval) so parseAndCompile cannot skip the gate.
 *
 *   php bin/vm.php test/repro/maintainer_gap_final_plain_direct.php
 *   # expect: Cannot declare property C::$x final, the final modifier is
 *   #         allowed only for methods, classes, and class constants
 *   #         (exit 255) — never "parsed_ok"
 *
 * PROFILE=8.4 allows plain finals; use final_plain_property_write / 8.4 repros.
 */
class C {
    public final string $x = 'a';
}
echo "parsed_ok\n";
