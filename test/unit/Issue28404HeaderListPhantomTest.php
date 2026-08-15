<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** #28404 — header_list absent on php-src-strict profiles (headers_list only). */
final class Issue28404HeaderListPhantomTest extends TestCase
{
    public function testPhantomAbsentOnDefaultAndForwardProfiles(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach ([false, '8.4', '8.5'] as $profile) {
            if (false === $profile) {
                putenv('PHP_COMPILER_PROFILE');
                $label = 'default';
            } else {
                putenv('PHP_COMPILER_PROFILE='.$profile);
                $label = $profile;
            }
            $this->assertFalse(CompilerVersion::supportsHeaderList(), $label);
            $this->assertFalse(CompilerVersion::advertisesHeaderList(), $label);

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['header_list']), $label);
            $this->assertTrue(isset($ctx->functions['headers_list']), $label);
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'header_list'),
                $label
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'headers_list'),
                $label
            );
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }
}
