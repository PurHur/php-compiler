<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregJitHelper;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmPregNative;
use PHPCompiler\ext\standard\VmPregPure;
use PHPUnit\Framework\TestCase;

/** preg_* /u rejects malformed UTF-8 subjects (#17503, ext/pcre/php_pcre.c). */
final class VmPregUtf8SubjectTest extends TestCase
{
    private const BAD = "\xFF";

    public function testPregMatchInvalidUtf8SetsBadUtf8Error(): void
    {
        VmPregPure::setLastError(0);
        $this->assertFalse(VmPregPure::pregMatch('//u', self::BAD));
        $this->assertSame(StdlibConstants::PREG_BAD_UTF8_ERROR, VmPregPure::lastError());
    }

    public function testPregSplitInvalidUtf8SetsBadUtf8Error(): void
    {
        VmPregPure::setLastError(0);
        $this->assertFalse(VmPregPure::pregSplit('//u', self::BAD));
        $this->assertSame(StdlibConstants::PREG_BAD_UTF8_ERROR, VmPregPure::lastError());
    }

    public function testPregReplaceInvalidUtf8ReturnsNull(): void
    {
        VmPregPure::setLastError(0);
        $this->assertNull(VmPregPure::pregReplace('//u', 'x', self::BAD));
        $this->assertSame(StdlibConstants::PREG_BAD_UTF8_ERROR, VmPregPure::lastError());
    }

    public function testJitHelperPropagatesBadUtf8Error(): void
    {
        VmPregNative::setLastError(0);
        $this->assertSame(-1, PregJitHelper::matchArgv('//u', self::BAD));
        $this->assertSame(StdlibConstants::PREG_BAD_UTF8_ERROR, PregJitHelper::lastError());
    }
}
