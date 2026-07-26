--TEST--
class_alias named class/alias arguments (VM, issue #23422)
--FILE--
<?php
class Orig {}
class_alias(class: 'Orig', alias: 'Alias1');
echo class_exists('Alias1') ? 'Y' : 'N', PHP_EOL;
$rf = new ReflectionFunction('class_alias');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), PHP_EOL;
try {
    class_alias(user_class_name: 'Orig', alias_name: 'Alias2');
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
Y
class,alias,autoload
Unknown named parameter $user_class_name
