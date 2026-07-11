<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHttpResponseConstants;
use PHPUnit\Framework\TestCase;

/** @covers VmHttpResponseConstants — HTTP_TOO_EARLY forward profile gate (#18059) */
final class VmHttpResponseConstantsTest extends TestCase
{
    public function testHttpTooEarlyConstantWithForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $constants = VmHttpResponseConstants::forwardProfileIntConstants();
            $this->assertSame(['HTTP_TOO_EARLY' => 425], $constants);
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
            $this->assertSame([], VmHttpResponseConstants::forwardProfileIntConstants());
        } finally {
            if (false !== $prev) {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
