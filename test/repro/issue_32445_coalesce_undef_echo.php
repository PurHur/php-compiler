<?php
/**
 * #32445 — echo $undef ?? default must match Zend ZEND_COALESCE (no notice).
 * AOT previously failed module verify: parentless load of phpc_script_global_*.
 */
echo $u ?? 'd';
echo "\n";
$x = null;
echo $x ?? 'n';
echo "\n";
$y = 'ok';
echo $y ?? 'n';
echo "\n";
