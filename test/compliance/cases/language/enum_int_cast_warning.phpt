--TEST--
Language: (int) cast on backed enum case — E_WARNING + backing int (#9479, Zend/zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; }
set_error_handler(static fn (): bool => true);
var_export((int) E::A);
echo "\n";
var_export(error_get_last()['message'] ?? 'no warning');
echo "\n";
var_dump((int) E::A);
?>
--EXPECT--
1
'no warning'
int(1)
