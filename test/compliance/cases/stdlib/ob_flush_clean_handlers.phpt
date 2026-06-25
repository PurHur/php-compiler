--TEST--
stdlib ob_flush / ob_clean / ob_list_handlers (issue #3588, ext/standard/output.c)
--FILE--
<?php
$hasFlush = function_exists('ob_flush');
$hasClean = function_exists('ob_clean');
$hasList = function_exists('ob_list_handlers');
var_export([$hasFlush, $hasClean, $hasList]);
echo "\n";

ob_start();
echo 'a';
ob_start();
echo 'b';
ob_flush();
$innerAfterFlush = ob_get_contents();
$levelAfterFlush = ob_get_level();
while (ob_get_level()) {
    ob_end_clean();
}
echo 'inner=' . $innerAfterFlush . ' level=' . $levelAfterFlush . "\n";

ob_start();
echo 'x';
ob_clean();
$cleanedLen = ob_get_length();
$cleanedLevel = ob_get_level();
$cleanOk = ob_clean();
ob_end_clean();
$cleanNoBuf = ob_clean();
echo 'len=' . $cleanedLen . ' level=' . $cleanedLevel . "\n";
echo 'ret=' . ($cleanOk ? 't' : 'f') . ($cleanNoBuf ? 't' : 'f') . "\n";

ob_start();
ob_start();
$handlers = ob_list_handlers();
while (ob_get_level()) {
    ob_end_clean();
}
var_export($handlers);
echo "\n";
--EXPECT--
array (
  0 => true,
  1 => true,
  2 => true,
)
inner= level=2
len=0 level=1
ret=tf
array (
  0 => 'default output handler',
  1 => 'default output handler',
)
