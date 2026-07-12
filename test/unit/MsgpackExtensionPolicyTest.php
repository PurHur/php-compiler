<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\msgpack\MsgpackExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** MsgpackExtensionPolicy phantom withhold on reference profile (#17994). */
final class MsgpackExtensionPolicyTest extends TestCase
{
    public function testAdvertisesExtensionFalseOnReferenceProfile(): void
    {
        self::assertFalse(CompilerVersion::supportsMsgpack());
        self::assertFalse(MsgpackExtensionPolicy::advertisesExtension());
    }

    public function testAdvertisesExtensionTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsMsgpack());
            self::assertTrue(MsgpackExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
