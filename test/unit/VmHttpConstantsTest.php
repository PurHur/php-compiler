<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StdlibModuleConstants;
use PHPCompiler\ext\standard\VmHttpConstants;
use PHPUnit\Framework\TestCase;

/** @covers VmHttpConstants — HTTP_TOO_EARLY forward-profile gate (#18059) */
final class VmHttpConstantsTest extends TestCase
{
    public function testHttpTooEarlyConstantWithForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $constants = VmHttpConstants::constants();
            $this->assertArrayHasKey('HTTP_TOO_EARLY', $constants);
            $this->assertSame(425, $constants['HTTP_TOO_EARLY']);
            $this->assertArrayHasKey('HTTP_TOO_EARLY', StdlibModuleConstants::bootstrapIntConstants());
            $this->assertSame(425, StdlibModuleConstants::bootstrapIntConstants()['HTTP_TOO_EARLY']);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testHttpTooEarlyConstantWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertSame([], VmHttpConstants::constants());
            $this->assertArrayNotHasKey('HTTP_TOO_EARLY', StdlibModuleConstants::bootstrapIntConstants());
        } finally {
            if (false !== $prev) {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
