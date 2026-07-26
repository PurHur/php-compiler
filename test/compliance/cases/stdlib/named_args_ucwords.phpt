--TEST--
ucwords named string/separators arguments (VM, issue #23226)
--FILE--
<?php
var_export(ucwords(string: 'hello world'));
echo PHP_EOL;
var_export(ucwords(string: 'a-b', separators: '-'));
echo PHP_EOL;
$rf = new ReflectionFunction('ucwords');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    ucwords(str: 'hello world', delims: '-');
    echo "str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
'Hello World'
'A-B'
string
separators
Unknown named parameter $str
