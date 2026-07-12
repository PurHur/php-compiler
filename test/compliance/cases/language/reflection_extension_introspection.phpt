--TEST--
Language: ReflectionExtension introspection API (#18326)
--FILE--
<?php
declare(strict_types=1);

$re = new ReflectionExtension('standard');
echo method_exists($re, 'getVersion') ? 'yes' : 'no', "\n";
echo method_exists($re, 'getFunctions') ? 'yes' : 'no', "\n";
echo method_exists($re, 'getClasses') ? 'yes' : 'no', "\n";
echo method_exists($re, 'getConstants') ? 'yes' : 'no', "\n";
echo $re->getName(), "\n";
echo count($re->getFunctions()) > 0 ? 'has-funcs' : 'no-funcs', "\n";
echo count($re->getClasses()) > 0 ? 'has-classes' : 'no-classes', "\n";
echo count($re->getConstants()) > 0 ? 'has-consts' : 'no-consts', "\n";
$spl = new ReflectionExtension('spl');
echo count($spl->getClasses()) > 0 ? 'spl-classes' : 'no-spl', "\n";
?>
--EXPECT--
yes
yes
yes
yes
standard
has-funcs
has-classes
has-consts
spl-classes
