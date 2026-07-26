--TEST--
strip_tags named string/allowed_tags arguments (VM, issue #23217)
--FILE--
<?php
var_export(strip_tags(string: '<b>x</b>', allowed_tags: '<b>'));
echo PHP_EOL;
var_export(strip_tags(string: '<b>x</b>'));
echo PHP_EOL;
$rf = new ReflectionFunction('strip_tags');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    strip_tags(str: '<b>x</b>', allowable_tags: '<b>');
    echo "str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
'<b>x</b>'
'x'
string
allowed_tags
Unknown named parameter $str
