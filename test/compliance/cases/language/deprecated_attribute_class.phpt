--TEST--
Language: #[\Deprecated] builtin attribute class — class_exists + ReflectionAttribute on function (#6369, #23701)
--FILE--
<?php
#[\Deprecated(message: "legacy", since: "8.4")]
function legacy_fn() {}

var_export(class_exists('Deprecated'));
echo "\n";
$rf = new ReflectionFunction('legacy_fn');
foreach ($rf->getAttributes() as $attr) {
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
