<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\phar\VmPhar;
use PHPUnit\Framework\TestCase;

/** Phar::running() path extraction — #3436; static capability helpers — #19871. */
final class PharRunningTest extends TestCase
{
    public function testRunningPathFromPlainScript(): void
    {
        self::assertSame('', VmPhar::runningPath('/tmp/repro.php', false));
    }

    public function testRunningPathFromPharScript(): void
    {
        self::assertSame(
            '/app/tool.phar',
            VmPhar::runningPath('/app/tool.phar/internal/index.php', false)
        );
    }

    public function testRunningPathFromPharUri(): void
    {
        self::assertSame(
            '/data/app.phar',
            VmPhar::runningPath('phar:///data/app.phar/bootstrap.php', false)
        );
    }

    public function testRunningAliasWhenRetPharTrue(): void
    {
        self::assertSame('tool', VmPhar::runningPath('/app/tool.phar/index.php', true));
    }

    public function testApiVersionConstant(): void
    {
        self::assertSame('1.1.1', VmPhar::API_VERSION);
    }

    public function testCanCompressRespectsZlibBz2(): void
    {
        self::assertSame(
            \extension_loaded('zlib') || \extension_loaded('bz2'),
            VmPhar::canCompress()
        );
        self::assertSame(\extension_loaded('zlib'), VmPhar::canCompress(VmPhar::COMPRESSED_GZ));
        self::assertSame(\extension_loaded('bz2'), VmPhar::canCompress(VmPhar::COMPRESSED_BZ2));
    }

    public function testIsValidPharFilenameExtensionRules(): void
    {
        self::assertTrue(VmPhar::isValidPharFilename('x.phar'));
        self::assertFalse(VmPhar::isValidPharFilename('x.txt'));
        self::assertFalse(VmPhar::isValidPharFilename('x.phar', false));
        self::assertTrue(VmPhar::isValidPharFilename('x.txt', false));
        self::assertFalse(VmPhar::isValidPharFilename('Foo.PHAR'));
        self::assertTrue(VmPhar::isValidPharFilename('Foo.PHAR', false));
    }
}
