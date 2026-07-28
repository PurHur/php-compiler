<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #17240 / #24002 — ArrayPadType never in php-src */
final class ArrayPadTypeEnumTest extends TestCase
{
    public function testArrayPadTypeWithheldOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertFalse(CompilerVersion::supportsArrayPadTypeEnum());
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->classes['arraypadtype']));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testArrayPadTypeWithheldOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->classes['arraypadtype']));
    }
}
