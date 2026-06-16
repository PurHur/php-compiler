<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\SetcookieOptions;
use PHPUnit\Framework\TestCase;

/** SetcookieOptions spine smoke helper (#8698). */
final class SetcookieOptionsSpineSmokeTest extends TestCase
{
    public function testSpineSmokeParseExercisesOptionsArrayPath(): void
    {
        $parsed = SetcookieOptions::spineSmokeParse();
        $this->assertSame('spine', $parsed['name']);
        $this->assertSame('ok', $parsed['value']);
        $this->assertSame('/', $parsed['path']);
    }
}
