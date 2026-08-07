<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPUnit\Framework\TestCase;

/** #28413 — free-function class_has_*() absent on php-src-strict profiles. */
final class Issue28413ClassHasFunctionsPhantomTest extends TestCase
{
    public function testPhantomAbsentOnProfile82Through85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.2', '8.3', '8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(CompilerVersion::supportsClassHasFunctions(), $profile);
            foreach (['class_has_method', 'class_has_property', 'class_has_constant'] as $fn) {
                $this->assertFalse(
                    BuiltinIntrospectionPolicy::functionIsAdvertised($fn),
                    $profile.':'.$fn
                );
            }

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            foreach (['class_has_method', 'class_has_property', 'class_has_constant'] as $fn) {
                $this->assertFalse(isset($ctx->functions[$fn]), $profile.':'.$fn);
                $this->assertFalse(
                    \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, $fn),
                    $profile.':'.$fn
                );
            }
            $this->assertTrue(isset($ctx->classes['reflectionclass']->methods['hasmethod']), $profile);
            $this->assertTrue(isset($ctx->classes['reflectionclass']->methods['hasproperty']), $profile);
            $this->assertTrue(isset($ctx->classes['reflectionclass']->methods['hasconstant']), $profile);
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }
}
