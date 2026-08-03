<?php
/**
 * Repro #27121 — AOT date('Y', strtotime(...)) must not segfault.
 *
 *   PHP_COMPILER_HELPER_RUNTIME_O=0 ./phpc build -o /tmp/dts test/repro/issue_27121_aot_date_strtotime.php
 *   /tmp/dts
 */
echo date('Y', strtotime('2020-01-02')), "\n";
