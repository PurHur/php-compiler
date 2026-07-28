--TEST--
stdlib setcookie/setrawcookie Zend stub named expires_or_options JIT (#23360, ext/standard/head.c)
--FILE--
<?php
$lines = [];
foreach (['setcookie', 'setrawcookie'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    $lines[] = $fn.'_params:'.implode(',', $names);
    try {
        $ok = $fn(name: 'n', value: 'v', expires_or_options: 0);
        $lines[] = $fn.'_named:'.($ok ? '1' : '0');
    } catch (Throwable $e) {
        $lines[] = $fn.'_named_err:'.$e->getMessage();
    }
    try {
        $fn(name: 'n', value: 'v', expires: 0);
        $lines[] = $fn.'_expires_legacy:accepted';
    } catch (Throwable $e) {
        $lines[] = $fn.'_expires_legacy:'.$e->getMessage();
    }
}
echo implode(PHP_EOL, $lines), PHP_EOL;
?>
--EXPECT--
setcookie_params:name,value,expires_or_options,path,domain,secure,httponly
setcookie_named:1
setcookie_expires_legacy:Unknown named parameter $expires
setrawcookie_params:name,value,expires_or_options,path,domain,secure,httponly
setrawcookie_named:1
setrawcookie_expires_legacy:Unknown named parameter $expires
