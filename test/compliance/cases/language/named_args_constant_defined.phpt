--TEST--
constant/defined named name/constant_name arguments (VM, issue #23434)
--FILE--
<?php
define('K', 1);
echo constant(name: 'K'), PHP_EOL;
echo defined(constant_name: 'K') ? 'Y' : 'N', PHP_EOL;
$rf = new ReflectionFunction('constant');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), PHP_EOL;
$rf = new ReflectionFunction('defined');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), PHP_EOL;
try {
    constant(const_name: 'K');
    echo "legacy constant accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
try {
    defined(name: 'K');
    echo "legacy defined accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
1
Y
name
constant_name
Unknown named parameter $const_name
Unknown named parameter $name
