--TEST--
stdlib error_get_last() includes file/line for class_alias warnings (#13407, Zend/zend_errors.c)
--FILE--
<?php
declare(strict_types=1);

$classAliasLine = __LINE__ + 1;
class_alias('NoSuchClass', 'AliasMissing13407');
$last = error_get_last();

echo (($last['file'] ?? '') === __FILE__) ? 'file_ok' : 'file_bad';
echo "\n";
echo (($last['line'] ?? 0) === $classAliasLine) ? 'line_ok' : 'line_bad';
echo "\n";
--EXPECT--
file_ok
line_ok
