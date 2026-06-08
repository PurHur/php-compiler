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
