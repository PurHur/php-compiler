<?php
$x = null;
$x++;
echo "null pre-inc val=", var_export($x, true), "\n";

$x = null;
$ret = $x++;
echo "null post-inc ret=", var_export($ret, true), " val=", var_export($x, true), "\n";

$y++;
echo "undef val=", var_export($y, true), "\n";

$o = new stdClass;
$o->n++;
echo "dyn val=", var_export($o->n, true), "\n";
