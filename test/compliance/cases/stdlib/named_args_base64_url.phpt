--TEST--
base64_encode/urlencode/urldecode/rawurl* named string: arguments (VM, issue #23257)
--FILE--
<?php
foreach (['base64_encode', 'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode'] as $fn) {
    $rf = new ReflectionFunction($fn);
    foreach ($rf->getParameters() as $p) {
        echo $fn, '_param:', $p->getName(), PHP_EOL;
    }
}
echo 'base64_encode:', base64_encode(string: 'ab'), PHP_EOL;
echo 'urlencode:', urlencode(string: 'a b'), PHP_EOL;
echo 'urldecode:', urldecode(string: 'a+b'), PHP_EOL;
echo 'rawurlencode:', rawurlencode(string: 'a b'), PHP_EOL;
echo 'rawurldecode:', rawurldecode(string: 'a%20b'), PHP_EOL;
try {
    base64_encode(str: 'ab');
    echo "str accepted\n";
} catch (Throwable $e) {
    echo 'str:', $e->getMessage(), PHP_EOL;
}
--EXPECT--
base64_encode_param:string
urlencode_param:string
urldecode_param:string
rawurlencode_param:string
rawurldecode_param:string
base64_encode:YWI=
urlencode:a+b
urldecode:a b
rawurlencode:a%20b
rawurldecode:a b
str:Unknown named parameter $str
