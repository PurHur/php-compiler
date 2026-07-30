<?php
/**
 * Issue #25535 (re-#25322) — final plain properties via eval() on the reference
 * profile must compile-fatal like Zend 8.2 (Zend/zend_compile.c).
 *
 *   php test/repro/maintainer_gap_final_plain_property_accepted.php
 *   php bin/vm.php test/repro/maintainer_gap_final_plain_property_accepted.php
 *   php bin/jit.php test/repro/maintainer_gap_final_plain_property_accepted.php
 *   # expect exit 255 + Cannot declare property T::$x final...
 *   # never: parsed_ok
 *
 * PROFILE=8.4 allows plain finals; see maintainer_gap_final_plain_properties_84.php.
 */
try {
    eval('class T { final public int $x = 1; }');
    echo "parsed_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
