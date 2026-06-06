--TEST--
Language: ++/-- on unbound local — E_WARNING Undefined variable (zend_variables.c, issue #6800)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$x++;
echo "post_inc=", var_export($x, true), "\n";

$y--;
echo "post_dec=", var_export($y, true), "\n";

++$z;
echo "pre_inc=", var_export($z, true), "\n";

--$w;
echo "pre_dec=", var_export($w, true), "\n";
--EXPECT--
W:Undefined variable $x
post_inc=1
W:Undefined variable $y
post_dec=NULL
W:Undefined variable $z
pre_inc=1
W:Undefined variable $w
pre_dec=NULL
