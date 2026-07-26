--TEST--
ctype_* named text argument (VM, issue #23192)
--FILE--
<?php
var_export(ctype_digit(text: '123'));
echo PHP_EOL;
var_export(ctype_alnum(text: 'A1'));
echo PHP_EOL;
var_export(ctype_alpha(text: 'Ab'));
echo PHP_EOL;
foreach (['ctype_digit', 'ctype_alnum', 'ctype_space', 'ctype_xdigit'] as $f) {
    $rf = new ReflectionFunction($f);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ':', implode(',', $names), PHP_EOL;
}
try {
    ctype_digit(c: '123');
    echo "c accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
true
true
true
ctype_digit:text
ctype_alnum:text
ctype_space:text
ctype_xdigit:text
Unknown named parameter $c
