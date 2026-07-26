--TEST--
strtr named string/from/to arguments (VM, issue #23215)
--FILE--
<?php
var_export(strtr(string: 'abc', from: 'a', to: 'x'));
echo PHP_EOL;
var_export(strtr(string: 'baab', from: ['a' => 'o']));
echo PHP_EOL;
$rf = new ReflectionFunction('strtr');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    strtr(str: 'abc', from: 'a', to: 'x');
    echo "str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
'xbc'
'boob'
string
from
to
Unknown named parameter $str
