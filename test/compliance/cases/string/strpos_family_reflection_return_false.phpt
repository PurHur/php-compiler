--TEST--
strpos family Reflection return includes |false (#25442, ext/standard/string.stub.php)
--FILE--
<?php
declare(strict_types=1);
foreach (['strpos', 'stripos', 'strrpos', 'strripos', 'strstr', 'stristr', 'strrchr'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
var_export(strpos('abc', 'z'));
echo "\n";
var_export(strstr('abc', 'z'));
echo "\n";
--EXPECT--
strpos ret=int|false
stripos ret=int|false
strrpos ret=int|false
strripos ret=int|false
strstr ret=string|false
stristr ret=string|false
strrchr ret=string|false
false
false
