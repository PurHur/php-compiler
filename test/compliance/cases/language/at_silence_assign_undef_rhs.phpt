--TEST--
Language: @$undef as assignment RHS is silenced; value remains null (#29132)
--FILE--
<?php
error_reporting(E_ALL);
$a = @$undef_assign_rhs_29132;
var_dump($a);
echo "ok\n";
@$bare_undef_29132;
$b = [];
$c = @$b['missing_29132'];
echo "ok2\n";
--EXPECT--
NULL
ok
ok2
