--TEST--
str_repeat named string/times arguments (VM, issue #23204)
--FILE--
<?php
var_export(str_repeat(string: 'x', times: 3));
echo PHP_EOL;
$rf = new ReflectionFunction('str_repeat');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
--EXPECT--
'xxx'
string
times
