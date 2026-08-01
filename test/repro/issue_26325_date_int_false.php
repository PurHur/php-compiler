<?php
// #26325 — strtotime/mktime/gmmktime Reflection return int|false (php_date.stub.php).
foreach (['strtotime', 'mktime', 'gmmktime'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
