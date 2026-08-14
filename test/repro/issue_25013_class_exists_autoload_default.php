<?php
// #25013 — class_exists Reflection $autoload default true (Zend/zend_builtin_functions.stub.php)
$r = new ReflectionFunction('class_exists');
$p = $r->getParameters()[1];
echo 'autoload_name=', $p->getName(), "\n";
echo 'autoload_default=';
try {
    var_export($p->getDefaultValue());
} catch (Throwable $e) {
    echo 'OPT';
}
echo "\n";
echo 'required=', $r->getNumberOfRequiredParameters(), "\n";

$hits = [];
spl_autoload_register(function ($c) use (&$hits) {
    $hits[] = $c;
});
class_exists('MissingA25013');
class_exists('MissingB25013', false);
echo 'hits=', implode(',', $hits), "\n";
