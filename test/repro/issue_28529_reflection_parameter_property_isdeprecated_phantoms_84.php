<?php

declare(strict_types=1);

/**
 * #28529 — ReflectionParameter/Property::isDeprecated are phantoms vs php-src.
 * Zend only has isDeprecated on ReflectionFunctionAbstract (+ Method) and
 * ReflectionClassConstant / ReflectionConstant (ext/reflection/php_reflection.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28529_reflection_parameter_property_isdeprecated_phantoms_84.php
 */
$bad = [];
if (method_exists(ReflectionParameter::class, 'isDeprecated')) {
    $bad[] = 'ReflectionParameter::isDeprecated';
}
if (method_exists(ReflectionProperty::class, 'isDeprecated')) {
    $bad[] = 'ReflectionProperty::isDeprecated';
}
if ($bad !== []) {
    echo 'phantoms:' . implode(',', $bad) . "\n";
    exit(1);
}
echo "ok\n";
