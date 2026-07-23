<?php
// #22513 — ReflectionAttribute has no PHP-visible properties (php-src).
#[Attribute]
class Attr
{
}

#[Attr]
class X
{
}

$ra = (new ReflectionClass(X::class))->getAttributes()[0];
foreach (['name', 'args', 'isRepeated', 'target'] as $p) {
    echo "property_exists $p=", var_export(property_exists($ra, $p), true), "\n";
    echo "isset $p=", var_export(isset($ra->$p), true), "\n";
}
echo 'getName=', $ra->getName(), "\n";
echo 'getTarget=', $ra->getTarget(), "\n";
echo 'isRepeated=', var_export($ra->isRepeated(), true), "\n";
echo 'gov=', json_encode(get_object_vars($ra)), "\n";
ob_start();
var_dump($ra);
$dump = ob_get_clean();
echo (false !== strpos($dump, '["name"]') || false !== strpos($dump, "[\"name\"]") || false !== strpos($dump, 'name"]'))
    ? "dump=leaks\n"
    : "dump=empty-shape\n";
// Zend: object(ReflectionAttribute)#N (0) {
echo (preg_match('/object\(ReflectionAttribute\)#\d+ \(0\)/', $dump) || preg_match('/object\(ReflectionAttribute\)#\d+ \(0\) \{/', $dump))
    ? "dump=zero-props\n"
    : "dump=nonzero\n";
