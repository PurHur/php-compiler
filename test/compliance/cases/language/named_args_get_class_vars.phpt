--TEST--
get_class_vars named class argument (VM, issue #23947)
--FILE--
<?php
class C
{
    public $a = 1;
}
echo array_key_exists('a', get_class_vars(class: 'C')) ? 'yes' : 'no', PHP_EOL;
$rf = new ReflectionFunction('get_class_vars');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'get_class_vars:', implode(',', $names), PHP_EOL;
try {
    get_class_vars(class_name: 'C');
    echo "class_name accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
yes
get_class_vars:class
Unknown named parameter $class_name
