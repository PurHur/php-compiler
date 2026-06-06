--TEST--
language: ClosedGeneratorException builtin class (Zend/zend_generators.c, #7160)
--FILE--
<?php
var_export(class_exists('ClosedGeneratorException', false));
echo "\n";
var_export(is_subclass_of('ClosedGeneratorException', 'Exception'));
echo "\n";
var_export(is_a('ClosedGeneratorException', 'Throwable', true));
echo "\n";

try {
    throw new ClosedGeneratorException('closed');
} catch (ClosedGeneratorException $e) {
    echo 'caught:', $e->getMessage(), "\n";
} catch (Exception $e) {
    echo 'parent_catch:', $e->getMessage(), "\n";
}
--EXPECT--
true
true
true
caught:closed
