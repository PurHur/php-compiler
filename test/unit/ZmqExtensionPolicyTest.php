<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\zmq\ZmqExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** ZmqExtensionPolicy host / ENABLE gate (#23964). */
final class ZmqExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostZmq(): void
    {
        if (\extension_loaded('zmq')) {
            self::markTestSkipped('host ext/zmq loaded');
        }

        self::assertFalse(ZmqExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('zmq')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'zmq_context')
        );
        self::assertFalse(
            isset($runtime->vmContext->classes['zmq'])
            || isset($runtime->vmContext->classes['zmqcontext'])
        );
    }

    public function testProfile84AloneDoesNotInventZmq(): void
    {
        if (\extension_loaded('zmq')) {
            self::markTestSkipped('host ext/zmq loaded');
        }

        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        $prevEnable = getenv('PHP_COMPILER_ENABLE_ZMQ');
        putenv('PHP_COMPILER_PROFILE=8.4');
        putenv('PHP_COMPILER_ENABLE_ZMQ');
        try {
            self::assertFalse(ZmqExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_ZMQ');
            } else {
                putenv('PHP_COMPILER_ENABLE_ZMQ='.$prevEnable);
            }
        }
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('zmq')) {
            self::markTestSkipped('host ext/zmq loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_ZMQ');
        putenv('PHP_COMPILER_ENABLE_ZMQ=1');
        try {
            self::assertTrue(ZmqExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_ZMQ');
            } else {
                putenv('PHP_COMPILER_ENABLE_ZMQ='.$prevEnable);
            }
        }
    }
}
