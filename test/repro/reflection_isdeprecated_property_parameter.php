<?php
declare(strict_types=1);

/**
 * #28529 / #23701 — Parameter/Property isDeprecated are phantoms vs php-src.
 * Expect method_exists false on all php-src-strict profiles.
 */
echo 'ReflectionProperty::isDeprecated exists: ', var_export(method_exists(ReflectionProperty::class, 'isDeprecated'), true), "\n";
echo 'ReflectionParameter::isDeprecated exists: ', var_export(method_exists(ReflectionParameter::class, 'isDeprecated'), true), "\n";
