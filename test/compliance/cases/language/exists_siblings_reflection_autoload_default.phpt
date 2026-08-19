--TEST--
interface_exists/trait_exists/enum_exists Reflection $autoload default true (#25030, Zend/zend_builtin_functions.stub.php)
--FILE--
<?php
foreach (['interface_exists', 'trait_exists', 'enum_exists'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $params = $rf->getParameters();
    echo $fn, '_name=', $params[0]->getName(), "\n";
    $p = $params[1];
    echo $fn, '_autoload_default=', var_export($p->getDefaultValue(), true), "\n";
    echo $fn, '_required=', $rf->getNumberOfRequiredParameters(), "\n";
}

$rf = new ReflectionFunction('is_subclass_of');
$params = $rf->getParameters();
echo 'is_subclass_of_allow_string=', var_export($params[2]->getDefaultValue(), true), "\n";
?>
--EXPECT--
interface_exists_name=interface
interface_exists_autoload_default=true
interface_exists_required=1
trait_exists_name=trait
trait_exists_autoload_default=true
trait_exists_required=1
enum_exists_name=enum
enum_exists_autoload_default=true
enum_exists_required=1
is_subclass_of_allow_string=true
