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
        $this->assertSame(CompilerVersion::VERSION, $var->toString());
        $this->assertSame(VmInfo::phpversion(), $var->toString());
    }

    public function testPhpVersionIdMatchesCompilerVersion(): void
    {
        $var = VmPhpCoreConstants::fetch('PHP_VERSION_ID');
        $this->assertNotNull($var);
        $this->assertSame(CompilerVersion::VERSION_ID, $var->toInt());
    }

    public function testFetchExactRejectsLowercasePhpVersion(): void
    {
        $this->assertNull(VmPhpCoreConstants::fetchExact('php_version'));
        $this->assertNotNull(VmPhpCoreConstants::fetchExact('PHP_VERSION'));
    }
}
