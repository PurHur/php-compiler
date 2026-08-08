<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Builtin\StringCaseCompare;
use PHPCompiler\JIT\Builtin\StringStrcoll;
use PHPUnit\Framework\TestCase;

/**
 * PHP string bridges must not export libc symbol names — AOT interposition breaks
 * libxcrypt crypt(3) (password_hash/crypt return *0 / SEGV) (#26861).
 */
final class LibcNameCollisionRuntimeShrinkTest extends TestCase
{
    public function testStringStrspnUsesCompilerPrefixedAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrspn.php');
        $this->assertStringContainsString('__compiler_strspn', $source);
        $this->assertStringContainsString('__compiler_strcspn', $source);
        $this->assertStringNotContainsString("'strspn'", $source);
        $this->assertStringNotContainsString("'strcspn'", $source);
    }

    public function testStringCaseCompareUsesCompilerPrefixedAbi(): void
    {
        $this->assertSame('__compiler_strcasecmp', StringCaseCompare::ABI_STRCASECMP);
        $this->assertSame('__compiler_strncasecmp', StringCaseCompare::ABI_STRNCASECMP);
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringCaseCompare.php');
        $this->assertStringContainsString('__compiler_strcasecmp', $source);
        $this->assertStringContainsString('__compiler_strncasecmp', $source);
    }

    public function testStringStrcollUsesCompilerPrefixedAbi(): void
    {
        $this->assertSame('__compiler_strcoll', StringStrcoll::ABI_STRCOLL);
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrcoll.php');
        $this->assertStringContainsString('__compiler_strcoll', $source);
    }

    public function testLibcExternDeclaresLiveStrcollNotDeadStrspnStrcspn(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        // strspn/strcspn dropped — StringStrspn owns __compiler_* ABIs (#28850, #29050)
        $this->assertStringNotContainsString("'strspn' =>", $source);
        $this->assertStringNotContainsString("'strcspn' =>", $source);
        $this->assertStringContainsString("'strcoll' =>", $source);
    }
}
