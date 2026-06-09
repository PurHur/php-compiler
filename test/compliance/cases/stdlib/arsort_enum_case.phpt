--TEST--
stdlib arsort() preserves backed enum case objects (#6150, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
$a = ['k' => E::B, 'a' => E::A];
arsort($a);
$names = [];
foreach ($a as $v) {
    $names[] = $v->name;
}
echo implode(',', $names), "\n";
--EXPECT--
B,A
