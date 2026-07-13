<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\xmlrpc\XmlrpcExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** XmlrpcExtensionPolicy phantom withhold on reference profile (#18503). */
final class XmlrpcExtensionPolicyTest extends TestCase
{
    public function testAdvertisesExtensionFalseOnReferenceProfile(): void
    {
        self::assertFalse(CompilerVersion::supportsXmlrpc());
        self::assertFalse(XmlrpcExtensionPolicy::advertisesExtension());
    }

    public function testAdvertisesExtensionTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsXmlrpc());
            self::assertTrue(XmlrpcExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
