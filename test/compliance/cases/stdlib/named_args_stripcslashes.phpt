--TEST--
stripcslashes named string argument (VM, issue #24865)
--FILE--
<?php
var_export(stripcslashes(string: "a\\nb"));
echo PHP_EOL;
$rf = new ReflectionFunction('stripcslashes');
foreach ($rf->getParameters() as $p) {
    echo 'stripcslashes:', $p->getName(), PHP_EOL;
}
try {
    stripcslashes(str: "a\\nb");
    echo "str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
'a
b'
stripcslashes:string
Unknown named parameter $str
