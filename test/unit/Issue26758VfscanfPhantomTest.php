<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** #26758 — vfscanf absent on php-src-strict profiles (sscanf/fscanf only). */
final class Issue26758VfscanfPhantomTest extends TestCase
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
            $this->assertFalse(CompilerVersion::supportsVfscanf(), $label);
            $this->assertFalse(CompilerVersion::advertisesVfscanf(), $label);

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['vfscanf']), $label);
            $this->assertTrue(isset($ctx->functions['sscanf']), $label);
            $this->assertTrue(isset($ctx->functions['fscanf']), $label);
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'vfscanf'),
                $label
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'fscanf'),
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
