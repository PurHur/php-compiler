--TEST--
stdlib gzencode/gzdecode Reflection return string|false (#25511)
--FILE--
<?php
foreach (['gzencode', 'gzdecode'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
$bytes = gzencode('hi');
echo 'round=', gzdecode($bytes), "\n";
?>
--EXPECT--
gzencode ret=string|false
gzdecode ret=string|false
round=hi
