--TEST--
stdlib range() array/object endpoint coerce — Zend 8.2 (#23592, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

error_reporting(E_ALL);
$warnings = [];
set_error_handler(function (int $no, string $str) use (&$warnings): bool {
    $warnings[] = $str;
    return true;
});

echo json_encode(range([], 2)), "\n";
echo json_encode(range(new stdClass(), 2)), "\n";
echo implode("\n", $warnings), "\n";
--EXPECT--
[0,1,2]
[1,2]
Object of class stdClass could not be converted to int
