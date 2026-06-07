--TEST--
Language: ++/-- on null coerces to int like Zend (zend_operators.c, #7435)
--FILE--
<?php
$x = null;
$x++;
echo "pre-inc val=", var_export($x, true), "\n";

$x = null;
$ret = $x++;
echo "post-inc ret=", var_export($ret, true), " val=", var_export($x, true), "\n";

$x = null;
$x--;
echo "pre-dec val=", var_export($x, true), "\n";

$x = null;
$ret = $x--;
echo "post-dec ret=", var_export($ret, true), " val=", var_export($x, true), "\n";

$y++;
echo "undef val=", var_export($y, true), "\n";

$o = new stdClass;
$o->n++;
echo "dyn val=", var_export($o->n, true), "\n";
?>
--EXPECT--
PHP Warning:  Undefined variable $y
pre-inc val=1
post-inc ret=NULL val=1
pre-dec val=NULL
post-dec ret=NULL val=NULL
undef val=1
dyn val=1
