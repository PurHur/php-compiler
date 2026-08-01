<?php
/**
 * #26359 — is_a / is_subclass_of Reflection $object_or_class is mixed
 * (Zend/zend_builtin_functions.stub.php).
 */
foreach (['is_a', 'is_subclass_of'] as $f) {
    $p = (new ReflectionFunction($f))->getParameters()[0];
    echo $f,
        ' ', $p->getName(),
        ' type=', ($p->hasType() ? (string) $p->getType() : '(none)'),
        PHP_EOL;
}
class A {}
class B extends A {}
echo 'is_a=', var_export(is_a(object_or_class: new B, class: 'A'), true), PHP_EOL;
echo 'is_subclass_of=', var_export(is_subclass_of(object_or_class: 'B', class: 'A'), true), PHP_EOL;
