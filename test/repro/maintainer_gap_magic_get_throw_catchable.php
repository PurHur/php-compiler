<?php
/**
 * Issue #25911 — exception thrown from __get must be catchable (Zend try/catch).
 *
 *   php test/repro/maintainer_gap_magic_get_throw_catchable.php
 *   php bin/vm.php test/repro/maintainer_gap_magic_get_throw_catchable.php
 *   php bin/jit.php test/repro/maintainer_gap_magic_get_throw_catchable.php
 *
 * Expect:
 *   caught=RuntimeException:get missing
 *   after
 */
class MagicGetThrow {
    public function __get(string $name) {
        throw new RuntimeException("get $name");
    }
}
try {
    echo (new MagicGetThrow)->missing;
} catch (Throwable $e) {
    echo "caught=", get_class($e), ":", $e->getMessage(), "\n";
}
echo "after\n";
