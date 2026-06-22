--TEST--
Language: (int) cast on backed enum case — E_WARNING + backing int (#9479, Zend/zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; }
$msgs = [];
set_error_handler(static function ($n, $m) use (&$msgs): bool { $msgs[] = $m; return true; });
var_export((int) E::A);
echo "\n";
var_export($msgs[0] ?? 'no warning');
echo "\n";
var_dump((int) E::A);
?>
--EXPECT--
1
'Object of class E could not be converted to int'
int(1)
