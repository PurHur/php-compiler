<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmInfo;
use PHPCompiler\ext\standard\VmPhpCoreConstants;
use PHPUnit\Framework\TestCase;

/** @covers VmPhpCoreConstants — Zend defined() in class scope (#1492 bootstrap-selfhost-helloworld) */
final class VmPhpCoreConstantsTest extends TestCase
{
    /**
     * fetch() runs defined() from a final class; magic names must not throw.
     *
     * @dataProvider magicConstantNameProvider
     */
    public function testFetchMagicConstantNamesFromClassScopeReturnNull(string $name): void
    {
        $this->assertNull(VmPhpCoreConstants::fetch($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function magicConstantNameProvider(): iterable
    {
        yield 'parent lowercase' => ['parent'];
        yield 'parent uppercase' => ['PARENT'];
        yield 'self' => ['self'];
        yield 'static' => ['static'];
        yield 'parent qualified' => ['parent::FOO'];
        yield 'Parent qualified' => ['Parent::class'];
    }

    public function testFetchPhpVersionFromClassScope(): void
    {
        $var = VmPhpCoreConstants::fetch('PHP_VERSION');
        $this->assertNotNull($var);
        $reported = CompilerVersion::reportedPhpVersion();
        $this->assertSame($reported, $var->toString());
        $this->assertSame(VmInfo::phpversion(), $var->toString());
    }

    public function testPhpVersionIdMatchesReportedVersion(): void
    {
        $var = VmPhpCoreConstants::fetch('PHP_VERSION_ID');
        $this->assertNotNull($var);
        $this->assertSame(CompilerVersion::reportedPhpVersionId(), $var->toInt());
    }

    public function testFetchExactRejectsLowercasePhpVersion(): void
    {
        $this->assertNull(VmPhpCoreConstants::fetchExact('php_version'));
        $this->assertNotNull(VmPhpCoreConstants::fetchExact('PHP_VERSION'));
    }

    public function testMainCoreExtrasDefinedAndMatchBucket(): void
    {
        $names = [
            'UPLOAD_ERR_OK',
            'UPLOAD_ERR_NO_FILE',
            'DEFAULT_INCLUDE_PATH',
            'PEAR_INSTALL_DIR',
            'PEAR_EXTENSION_DIR',
            'ZEND_THREAD_SAFE',
            'ZEND_DEBUG_BUILD',
        ];
        $bucket = VmPhpCoreConstants::categorizedCoreEntries();
        foreach ($names as $name) {
            $exact = VmPhpCoreConstants::fetchExact($name);
            $this->assertNotNull($exact, $name.' fetchExact');
            $this->assertArrayHasKey($name, $bucket, $name.' bucket');
            $this->assertNull(VmPhpCoreConstants::fetchExact(strtolower($name)), $name.' lowercase');
        }
        $this->assertSame(0, VmPhpCoreConstants::fetchExact('UPLOAD_ERR_OK')->toInt());
        $this->assertSame(4, VmPhpCoreConstants::fetchExact('UPLOAD_ERR_NO_FILE')->toInt());
        $this->assertTrue(is_string(VmPhpCoreConstants::fetchExact('DEFAULT_INCLUDE_PATH')->toString()));
        $this->assertIsBool(VmPhpCoreConstants::fetchExact('ZEND_THREAD_SAFE')->toBool());
    }

    public function testTentativeReturnConstantWithForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertNotNull(VmPhpCoreConstants::fetchExact('TENTATIVE_RETURN'));
            $this->assertSame(1, VmPhpCoreConstants::fetchExact('TENTATIVE_RETURN')->toInt());
            $this->assertArrayHasKey('TENTATIVE_RETURN', VmPhpCoreConstants::categorizedCoreEntries());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testTentativeReturnConstantWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertNull(VmPhpCoreConstants::fetchExact('TENTATIVE_RETURN'));
        } finally {
            if (false !== $prev) {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #28170 — PHP_SBINDIR Core path constant on PROFILE≥8.4 */
    public function testPhpSbindirConstantWithForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsPhpSbindirConstant());
            $exact = VmPhpCoreConstants::fetchExact('PHP_SBINDIR');
            $this->assertNotNull($exact);
            $val = $exact->toString();
            $this->assertNotSame('', $val);
            $this->assertSame($val, VmPhpCoreConstants::phpSbindirValue());
            $this->assertArrayHasKey('PHP_SBINDIR', VmPhpCoreConstants::categorizedCoreEntries());
            $this->assertNull(VmPhpCoreConstants::fetchExact('php_sbindir'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #28170 — PHP_SBINDIR withheld on ≤8.3 */
    public function testPhpSbindirConstantWithheldOnProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertFalse(CompilerVersion::supportsPhpSbindirConstant());
            $this->assertNull(VmPhpCoreConstants::fetchExact('PHP_SBINDIR'));
            $this->assertArrayNotHasKey('PHP_SBINDIR', VmPhpCoreConstants::categorizedCoreEntries());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
