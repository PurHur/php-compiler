<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\exif\ExifImagetypeJitHelper;
use PHPUnit\Framework\TestCase;

/** exif_imagetype() JIT helper SSOT (#18181). */
final class ExifImagetypeJitHelperTest extends TestCase
{
    public function testMissingPathReturnsFailureSentinel(): void
    {
        $this->assertSame(-1, ExifImagetypeJitHelper::fromPath('/no/such/exif_imagetype_probe.jpg'));
    }
}
