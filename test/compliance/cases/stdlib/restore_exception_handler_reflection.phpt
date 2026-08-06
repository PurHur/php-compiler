--TEST--
Reflection: restore_exception_handler return true (sibling restore_error_handler) (#28223)
--FILE--
<?php
foreach (['restore_error_handler', 'restore_exception_handler'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '<none>', "\n";
}
--EXPECT--
restore_error_handler ret=true
restore_exception_handler ret=true
