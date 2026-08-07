<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPUnit\Framework\TestCase;

/** #28367 — stream_supports / STREAM_SUPPORT_* absent on php-src-strict forward profiles. */
final class Issue28367StreamSupportsPhantomTest extends TestCase
{
    public function testPhantomAbsentOnProfile84And85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach (['8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(CompilerVersion::supportsStreamSupports(), $profile);
            $this->assertFalse(CompilerVersion::advertisesStreamSupports(), $profile);
            $this->assertFalse(CompilerVersion::supportsStreamSupportReadWriteConstants(), $profile);
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('stream_supports'), $profile);

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['stream_supports']), $profile);
            $this->assertTrue(isset($ctx->functions['stream_supports_lock']), $profile);
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'stream_supports'),
                $profile
            );
            $this->assertTrue(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'stream_supports_lock'),
                $profile
            );
            $this->assertFalse(isset($ctx->constants['STREAM_SUPPORT_READ']), $profile);
            $this->assertFalse(isset($ctx->constants['STREAM_SUPPORT_WRITE']), $profile);
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }
}
