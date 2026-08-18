<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * User-script allocation stays MemoryManager __mm__* (#32273).
 * Libc malloc(3)/realloc(3)/free(3) are module-local after the LibcExtern always-on drop.
 */
final class MallocRuntimeShrinkTest extends TestCase
{
    public function testMemoryManagerNativeDeclaresMallocFamilyModuleLocally(): void
    {
        $native = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MemoryManager/Native.php');
        $this->assertStringContainsString('LibcExtern::ensureMallocFamily', $native);
        $this->assertStringContainsString('#32273', $native);
        $this->assertStringContainsString("lookupFunction('malloc')", $native);
        $pre = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MemoryManager/Native.pre');
        $this->assertStringContainsString('LibcExtern::ensureMallocFamily', $pre);
        $this->assertStringContainsString('#32273', $pre);
    }

    public function testLibcExternDropsAlwaysOnMallocFamily(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'malloc' =>", $source);
        $this->assertStringNotContainsString("'realloc' =>", $source);
        $this->assertStringNotContainsString("'free' =>", $source);
        $this->assertStringContainsString('ensureMallocFamily', $source);
        $this->assertStringContainsString('#32273', $source);
        $this->assertStringContainsString("'syscall' =>", $source);
        $this->assertStringContainsString("'__phpc_host_php_write' =>", $source);
        $this->assertStringContainsString("'__phpc_host_snprintf' =>", $source);
    }

    public function testPackArgvSerializeUsesCanonicalI8MallocNotVoidStar(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PackArgvSerialize.php');
        $this->assertStringContainsString('LibcExtern::ensureMallocFamily', $source);
        $this->assertStringContainsString('#32273', $source);
        $this->assertStringNotContainsString(
            "functionType(\$voidPtr, false, \$sizeT)",
            $source
        );
    }
}
