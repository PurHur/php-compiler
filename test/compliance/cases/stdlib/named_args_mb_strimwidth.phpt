--TEST--
mb_strimwidth named trim_marker argument (VM, issue #23351)
--FILE--
<?php
var_export(mb_strimwidth(string: 'hello', start: 0, width: 3, trim_marker: '..'));
echo PHP_EOL;
$rf = new ReflectionFunction('mb_strimwidth');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    mb_strimwidth(string: 'hello', start: 0, width: 3, trimmarker: '..');
    echo "trimmarker accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
'h..'
string
start
width
trim_marker
encoding
Unknown named parameter $trimmarker
