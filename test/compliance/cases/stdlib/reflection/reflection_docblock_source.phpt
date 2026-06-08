--TEST--
Stdlib: ReflectionClass/ReflectionMethod docblock + source location (#7358)
--FILE--
<?php
/** class doc */
class C {
    /** method doc */
    public function f() {}
}

$rc = new ReflectionClass(C::class);
$rm = new ReflectionMethod(C::class, 'f');

foreach (['getDocComment', 'getStartLine', 'getEndLine', 'getFileName', 'isUserDefined', 'getExtensionName'] as $m) {
    echo "ReflectionClass::$m ", (int) method_exists($rc, $m), "\n";
    echo "ReflectionMethod::$m ", (int) method_exists($rm, $m), "\n";
}
echo "class doc: ", var_export($rc->getDocComment(), true), "\n";
echo "method doc: ", var_export($rm->getDocComment(), true), "\n";
echo "class file: ", var_export($rc->getFileName(), true), "\n";
echo "method file: ", var_export($rm->getFileName(), true), "\n";
echo "class user: ", (int) $rc->isUserDefined(), "\n";
echo "method user: ", (int) $rm->isUserDefined(), "\n";
echo "class ext: ", var_export($rc->getExtensionName(), true), "\n";
echo "method ext: ", var_export($rm->getExtensionName(), true), "\n";
echo "class start: ", $rc->getStartLine(), "\n";
echo "method start: ", $rm->getStartLine(), "\n";
echo "class end: ", $rc->getEndLine(), "\n";
echo "method end: ", $rm->getEndLine(), "\n";
--EXPECTF--
ReflectionClass::getDocComment 1
ReflectionMethod::getDocComment 1
ReflectionClass::getStartLine 1
ReflectionMethod::getStartLine 1
ReflectionClass::getEndLine 1
ReflectionMethod::getEndLine 1
ReflectionClass::getFileName 1
ReflectionMethod::getFileName 1
ReflectionClass::isUserDefined 1
ReflectionMethod::isUserDefined 1
ReflectionClass::getExtensionName 1
ReflectionMethod::getExtensionName 1
class doc: '/** class doc */'
method doc: '/** method doc */'
class file: '-'
method file: '-'
class user: 1
method user: 1
class ext: false
method ext: false
class start: %d
method start: %d
class end: %d
method end: %d
