<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Internal builtin arginfo arity via ircmaxell/php-types (#11453). */
final class BuiltinInternalArgInfoTest extends TestCase
{
    public function testArrayMapParamCount(): void
    {
        $this->assertSame(3, BuiltinInternalArgInfo::paramCountForFunction('array_map'));
    }

    public function testBuiltinParamNamesTakesPrecedence(): void
    {
        $this->assertSame(1, BuiltinParamNames::paramCountForInternalFunction('strlen'));
        $this->assertSame(3, BuiltinParamNames::paramCountForInternalFunction('json_encode'));
    }

    public function testUnknownFunctionReturnsNull(): void
    {
        $this->assertNull(BuiltinInternalArgInfo::paramCountForFunction('not_a_real_builtin_xyz'));
    }
}
