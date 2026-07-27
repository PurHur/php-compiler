--TEST--
Language: << and >> with float operands truncate to int (JIT, #5270)
--FILE--
<?php
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});

var_dump(1.5 << 1);
echo 'warn_count=', count($seen), "\n";
echo 'warn_0=', $seen[0][1] ?? '', "\n";
?>
--EXPECT--
int(2)
warn_count=1
warn_0=Implicit conversion from float 1.5 to int loses precision
