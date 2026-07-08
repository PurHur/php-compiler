--TEST--
Language: #[\Deprecated] builtin attribute class — class_exists + ReflectionAttribute (#6369, zend_attributes.c)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== 'forward') {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
#[\Deprecated(message: "legacy", since: "8.4")]
class Legacy {}

var_export(class_exists('Deprecated'));
echo "\n";
$rc = new ReflectionClass(Legacy::class);
foreach ($rc->getAttributes() as $attr) {
    echo $attr->getName(), "\n";
    $inst = $attr->newInstance();
    echo $inst->message, "\n";
    echo $inst->since, "\n";
}
--EXPECT--
true
Deprecated
legacy
8.4
