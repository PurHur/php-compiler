--TEST--
Language: #[\DelayedTargetValidation] defers internal attribute target errors to newInstance (#26329, Zend/zend_attributes.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDelayedTargetValidationAttribute()) {
    die('skip requires PHP 8.5+ DelayedTargetValidation');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
#[\DelayedTargetValidation]
#[\Deprecated('x')]
class X {}
echo "deprecated_class=compiled\n";
foreach ((new ReflectionClass(X::class))->getAttributes(Deprecated::class) as $a) {
    try {
        $a->newInstance();
        echo "deprecated_class=instanced\n";
    } catch (Throwable $e) {
        echo "deprecated_class=", get_class($e), ':', $e->getMessage(), "\n";
    }
}

class Holder {
    #[\DelayedTargetValidation]
    #[\Override]
    public const NAME = 'c';
}
echo "override_const=compiled\n";
foreach ((new ReflectionClassConstant(Holder::class, 'NAME'))->getAttributes(Override::class) as $a) {
    try {
        $a->newInstance();
        echo "override_const=instanced\n";
    } catch (Throwable $e) {
        echo "override_const=", get_class($e), ':', $e->getMessage(), "\n";
    }
}

#[\DelayedTargetValidation]
#[\SensitiveParameter]
class Z {}
echo "sensitive_class=compiled\n";
foreach ((new ReflectionClass(Z::class))->getAttributes(SensitiveParameter::class) as $a) {
    try {
        $a->newInstance();
        echo "sensitive_class=instanced\n";
    } catch (Throwable $e) {
        echo "sensitive_class=", get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
deprecated_class=compiled
deprecated_class=Error:Cannot apply #[\Deprecated] to class X
override_const=compiled
override_const=Error:Attribute "Override" cannot target class constant (allowed targets: method, property)
sensitive_class=compiled
sensitive_class=Error:Attribute "SensitiveParameter" cannot target class (allowed targets: parameter)
