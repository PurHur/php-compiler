--TEST--
stdlib ob_flush() nested buffers — flush to parent not stdout (#11700, ext/standard/output.c)
--FILE--
<?php
ob_start();
echo 'a';
ob_start();
echo 'b';
ob_flush();
$inner = ob_get_clean();
$outer = ob_get_clean();
echo 'outer=' . var_export($outer, true) . "\n";
echo 'inner=' . var_export($inner, true) . "\n";
--EXPECT--
outer='ab'
inner=''
