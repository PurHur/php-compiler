<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPUnit\Framework\TestCase;

/** VmPregEngine capture group indices follow opening-paren order (#14574). */
final class VmPregEngineGroupIndexTest extends TestCase
{
    public function testNestedCaptureGroupNumberingMatchesPhpSrc(): void
    {
        $matches = [];
        VmPreg::pregMatch('/(a(b))/', 'ab', $matches, StdlibConstants::PREG_OFFSET_CAPTURE);
        $this->assertSame(['ab', 0], $matches[1]);
        $this->assertSame(['b', 1], $matches[2]);
    }
}
