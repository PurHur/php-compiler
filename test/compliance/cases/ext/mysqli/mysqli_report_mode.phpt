--TEST--
ext/mysqli mysqli_report() mode get/set (#21804, ext/mysqli/mysqli_report.c)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
echo function_exists('mysqli_report') ? 'yes' : 'no', "\n";
echo defined('MYSQLI_REPORT_OFF') ? 'yes' : 'no', "\n";
echo defined('MYSQLI_REPORT_STRICT') ? 'yes' : 'no', "\n";
$ok = mysqli_report(MYSQLI_REPORT_OFF);
echo $ok ? 'set_ok' : 'set_fail', "\n";
$ok2 = mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
echo $ok2 ? 'set_ok' : 'set_fail', "\n";
?>
--EXPECT--
yes
yes
yes
set_ok
set_ok
