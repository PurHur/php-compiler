<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guard: NestedJIT dllist serialize helper stays a single-method TU (#33966).
 * Live thin-AOT path is LLVM hashtable ABI (#34592) — NestedJIT kept for spine inventory.
 */
final class SplDllistSerialize33966AotTest extends TestCase
{
    public function testSerializeHelperIsSinglePublicMethod(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/ext/standard/SerializeSplDllistNestedJitHelper.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('encodeWire', $src);
        $this->assertStringContainsString('":3:{i:0;i:', $src);
        $this->assertStringContainsString('SplQueue', $src);
        $this->assertStringContainsString('SplStack', $src);
        $this->assertStringContainsString('#34592', $src);
        // NestedJIT blanks multi-method TUs (#27030).
        $this->assertSame(
            1,
            preg_match_all('/public static function /', $src),
            'SerializeSplDllistNestedJitHelper must expose exactly one public static method'
        );
    }

    public function testSpineRequiresSerializeHelper(): void
    {
        $root = dirname(__DIR__, 2);
        $spine = (string) file_get_contents(
            $root.'/test/selfhost/compiler_lib_spine_smoke/main.php'
        );
        $this->assertStringContainsString(
            'SerializeSplDllistNestedJitHelper.php',
            $spine
        );
    }

    public function testLiveCompileSerializeUsesHashtableAbi(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/VM/SplDllistJitHelper.php');
        $this->assertStringContainsString('__compiler_serialize_hashtable', $src);
        $this->assertStringNotContainsString(
            'SerializeSplDllistNestedJitHelper::encodeWire',
            $src
        );
    }
}
