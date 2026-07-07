--TEST--
stdlib compact() preserves backed and unit enum case objects (#8686, #5563)
--FILE--
<?php
enum E: int
{
    case A = 1;
}
enum U
{
    case A;
}
$a = E::A;
$u = U::A;
var_export(compact('a', 'u'));
echo "\n";
--EXPECT--
array (
  'a' => 
  \E::A,
  'u' => 
  \U::A,
)
