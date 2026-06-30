<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Dead libc strcasestr extern removed — stristr uses JitStringSearch only (#14070). */
final class StrcasestrDeletedShrinkTest extends TestCase
{
    public function testStrcasestrAbsentFromLibcExternAndModuleJitInit(): void
    {
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $module = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString('strcasestr', $libc);
        $this->assertStringNotContainsString('strcasestr', $module);
    }
}
