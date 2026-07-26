--TEST--
Undeclared static property is catchable Error with full Class::$name (#23606)
--FILE--
<?php
class C {}
try {
    var_export(C::$missing_name);
    echo " uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), "|", $e->getMessage(), "\n";
}
try {
    C::$missing_name = 1;
    echo "assign uncaught\n";
} catch (Throwable $e) {
    echo "assign:", get_class($e), "|", $e->getMessage(), "\n";
}
set_exception_handler(function (Throwable $e) {
    echo "handler:", get_class($e), "|", $e->getMessage(), "\n";
});
C::$also_missing;
--EXPECT--
Error|Access to undeclared static property C::$missing_name
assign:Error|Access to undeclared static property C::$missing_name
handler:Error|Access to undeclared static property C::$also_missing
