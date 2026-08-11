<?php
/** Issue #30030: $obj?->missing ?? default must be silent like $obj->missing ??. */
$b = new stdClass();
var_export($b?->foo ?? 'no');
echo PHP_EOL;
