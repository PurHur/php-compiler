--TEST--
Language: << and >> with float operands truncate to int (zend_operators.c, #5270)
--FILE--
<?php
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});

var_dump(1 << 1.5);
var_dump(1.5 << 1);
var_dump(8 >> 1.5);
echo 'warn_count=', count($seen), "\n";
echo 'warn_0=', $seen[0][1] ?? '', "\n";
echo 'warn_1=', $seen[1][1] ?? '', "\n";
echo 'warn_2=', $seen[2][1] ?? '', "\n";
?>
--EXPECT--
int(2)
int(2)
int(4)
warn_count=3
warn_0=Implicit conversion from float 1.5 to int loses precision
warn_1=Implicit conversion from float 1.5 to int loses precision
warn_2=Implicit conversion from float 1.5 to int loses precision
