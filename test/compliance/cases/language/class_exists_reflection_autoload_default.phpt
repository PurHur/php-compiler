--TEST--
class_exists Reflection $autoload default true (#25013, Zend/zend_builtin_functions.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('class_exists');
$params = $rf->getParameters();
echo 'class=', $params[0]->isDefaultValueAvailable() ? 'DEF' : 'REQ', "\n";
$p = $params[1];
echo 'autoload_name=', $p->getName(), "\n";
echo 'autoload_default=', var_export($p->getDefaultValue(), true), "\n";
echo 'required=', $rf->getNumberOfRequiredParameters(), "\n";

$hits = [];
spl_autoload_register(function ($c) use (&$hits) {
    $hits[] = $c;
});
class_exists('MissingA25013');
class_exists('MissingB25013', false);
echo 'hits=', implode(',', $hits), "\n";
?>
--EXPECT--
class=REQ
autoload_name=autoload
autoload_default=true
required=1
hits=MissingA25013
