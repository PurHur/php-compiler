--TEST--
zlib_encode/zlib_decode Reflection return string|false (#28349)
--FILE--
<?php
foreach (['zlib_encode', 'zlib_decode', 'gzencode'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, '=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
}
--EXPECT--
zlib_encode=string|false
zlib_decode=string|false
gzencode=string|false
