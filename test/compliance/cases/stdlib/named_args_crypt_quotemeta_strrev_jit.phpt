--TEST--
crypt / quotemeta / strrev / str_rot13 named string argument (JIT, issue #23264)
--JIT--
--FILE--
<?php
var_export(strrev(string: 'ab'));
echo PHP_EOL;
var_export(quotemeta(string: '.+'));
echo PHP_EOL;
var_export(str_rot13(string: 'ab'));
echo PHP_EOL;
$hash = crypt(string: 'x', salt: '$1$xxxxxxxx$');
var_export(is_string($hash) && '' !== $hash);
echo PHP_EOL;
foreach (['crypt', 'quotemeta', 'strrev', 'str_rot13'] as $fn) {
    $rf = new ReflectionFunction($fn);
    foreach ($rf->getParameters() as $p) {
        echo $fn, ':', $p->getName(), PHP_EOL;
    }
}
try {
    strrev(str: 'ab');
    echo "str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
'ba'
'\\.\\+'
'no'
true
crypt:string
crypt:salt
quotemeta:string
strrev:string
str_rot13:string
Unknown named parameter $str
