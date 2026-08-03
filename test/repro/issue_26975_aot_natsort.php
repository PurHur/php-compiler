<?php
/**
 * Issue #26975 — AOT natsort() must match Zend (no abort).
 *
 *   PHP_COMPILER_HELPER_RUNTIME_O=0 ./phpc build -o /tmp/aot_natsort test/repro/issue_26975_aot_natsort.php && /tmp/aot_natsort
 */
$a = ['img12.png', 'img10.png', 'img2.png'];
natsort($a);
echo implode(',', $a), PHP_EOL;
