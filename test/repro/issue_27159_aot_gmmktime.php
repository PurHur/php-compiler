<?php
/**
 * Repro #27159 — AOT gmmktime must return UTC unix timestamps (not empty).
 *
 *   PHP_COMPILER_HELPER_RUNTIME_O=0 ./phpc build -o /tmp/gm test/repro/issue_27159_aot_gmmktime.php
 *   /tmp/gm
 */
echo 'G', gmmktime(0, 0, 0, 1, 1, 1970), "G\n";
echo 'H', gmmktime(12, 0, 0, 1, 1, 2024), "H\n";
