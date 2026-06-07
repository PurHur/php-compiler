--TEST--
stdlib compact() preserves backed enum case objects (#5563)
--FILE--
<?php
enum E: int
{
    case A = 1;
}
$a = E::A;
var_export(compact('a'));
echo "\n";
--EXPECT--
array (
  'a' => \E::A,
)
