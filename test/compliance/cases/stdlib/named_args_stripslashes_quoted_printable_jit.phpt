--TEST--
stripslashes / quoted_printable_* named string argument (JIT, issue #23273)
--JIT--
--FILE--
<?php
var_export(stripslashes(string: 'a\\b'));
echo PHP_EOL;
var_export(quoted_printable_encode(string: 'a=b'));
echo PHP_EOL;
var_export(quoted_printable_decode(string: 'a=3Db'));
echo PHP_EOL;
foreach (['stripslashes', 'quoted_printable_encode', 'quoted_printable_decode'] as $fn) {
    $rf = new ReflectionFunction($fn);
    foreach ($rf->getParameters() as $p) {
        echo $fn, ':', $p->getName(), PHP_EOL;
    }
}
try {
    stripslashes(str: 'a\\b');
    echo "str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
'ab'
'a=3Db'
'a=b'
stripslashes:string
quoted_printable_encode:string
quoted_printable_decode:string
Unknown named parameter $str
