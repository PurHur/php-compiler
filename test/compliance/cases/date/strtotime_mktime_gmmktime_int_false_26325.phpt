--TEST--
strtotime/mktime/gmmktime Reflection return int|false (#26325, php_date.stub.php)
--FILE--
<?php
foreach (['strtotime', 'mktime', 'gmmktime'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
?>
--EXPECT--
strtotime ret=int|false
mktime ret=int|false
gmmktime ret=int|false
