--TEST--
stdlib gzcompress/gzuncompress/gzdeflate/gzinflate Reflection return string|false (#26342)
--FILE--
<?php
foreach (['gzcompress', 'gzuncompress', 'gzdeflate', 'gzinflate'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
$c = gzcompress('hi');
echo 'round=', gzuncompress($c), "\n";
$d = gzdeflate('hi');
echo 'raw=', gzinflate($d), "\n";
?>
--EXPECT--
gzcompress ret=string|false
gzuncompress ret=string|false
gzdeflate ret=string|false
gzinflate ret=string|false
round=hi
raw=hi
