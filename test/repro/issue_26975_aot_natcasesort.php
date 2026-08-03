<?php
/**
 * Issue #26975 — AOT natcasesort() must match Zend (no abort).
 *
 *   PHP_COMPILER_HELPER_RUNTIME_O=0 ./phpc build -o /tmp/aot_natcasesort test/repro/issue_26975_aot_natcasesort.php && /tmp/aot_natcasesort
 */
$a = ['A2', 'a10', 'A1'];
natcasesort($a);
echo implode(',', $a), PHP_EOL;
