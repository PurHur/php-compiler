<?php

declare(strict_types=1);

/**
 * #28518 — ReflectionClass::isStatic() is a phantom vs php-src.
 * php-src: isStatic on ReflectionFunctionAbstract / ReflectionProperty only
 * (ext/reflection/php_reflection.stub.php). Static-class RFC not merged.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_maintainer_reflectionclass_isstatic_phantom_84.php
 */
if (method_exists(ReflectionClass::class, 'isStatic')) {
    echo "phantom:ReflectionClass::isStatic\n";
    exit(1);
}

if (!method_exists(ReflectionProperty::class, 'isStatic')) {
    echo "fail: ReflectionProperty::isStatic missing\n";
    exit(1);
}

if (!method_exists(ReflectionMethod::class, 'isStatic')) {
    echo "fail: ReflectionMethod::isStatic missing\n";
    exit(1);
}

echo "ok\n";
