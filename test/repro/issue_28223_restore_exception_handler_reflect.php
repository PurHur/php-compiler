<?php
/** Repro #28223 — restore_exception_handler Reflection return true (Zend stub). */
foreach (['restore_error_handler', 'restore_exception_handler'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '<none>', "\n";
}
