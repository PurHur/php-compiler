<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SprintfJitHelper must not call unpack() — it is force-NestedJIT'd into every user-script
 * AOT binary; unpack() pulls UnpackEngine and OOMs Slim-sized graphs (#36382).
 *
 * @group unit
 */
final class SprintfJitHelperNoUnpack36382Test extends TestCase
{
    public function testReadPackedDoubleAvoidsUnpackBuiltin(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/SprintfJitHelper.php');
        $pos = strpos($src, 'function readPackedDoubleAtOffset');
        $this->assertNotFalse($pos);
        $end = strpos($src, 'function packedArgByteSizeAtOffset', $pos);
        $this->assertNotFalse($end);
        $fn = substr($src, $pos, $end - $pos);
        $codeOnly = preg_replace('!//.*!m', '', $fn) ?? $fn;
        $this->assertStringNotContainsString('unpack(', $codeOnly);
        $this->assertStringContainsString('Ieee754::decodeFloat64Le', $fn);
    }

    public function testStringFormatBundlesIeee754BeforeSprintf(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/StringFormat.php');
        $this->assertStringContainsString('ensureCompiledBundle', $src);
        $pos = strpos($src, 'private const HELPER_BUNDLE');
        $this->assertNotFalse($pos);
        $window = substr($src, $pos, 350);
        $this->assertStringContainsString('Ieee754.php', $window);
        $this->assertStringContainsString('self::HELPER_PATH', $window);
        $ieee = strpos($window, 'Ieee754.php');
        $helperPath = strpos($window, 'self::HELPER_PATH');
        $this->assertLessThan($helperPath, $ieee);
    }
}
