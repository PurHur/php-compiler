--TEST--
stdlib preg_last_error_msg/error_clear_last Reflection returns (#25623)
--FILE--
<?php
foreach (['preg_last_error_msg', 'error_clear_last'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, '|ret:', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
}
@preg_match('/(/', 'x');
echo 'msg=', preg_last_error_msg(), "\n";
@trigger_error('t', E_USER_NOTICE);
error_clear_last();
var_export(error_get_last());
echo "\n";
?>
--EXPECT--
preg_last_error_msg|ret:string
error_clear_last|ret:void
msg=Internal error
NULL
