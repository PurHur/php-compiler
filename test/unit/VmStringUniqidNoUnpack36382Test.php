<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * VmString::uniqid must not call unpack() — NestedJIT of VmString (via parse_url)
 * would otherwise pull UnpackEngine into Slim-sized AOT emits and OOM (#36382).
 *
 * @group unit
 */
final class VmStringUniqidNoUnpack36382Test extends TestCase
{
    public function testUniqidSourceAvoidsUnpackBuiltin(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/VmString.php');
        $pos = strpos($src, 'public static function uniqid(');
        $this->assertNotFalse($pos);
        $end = strpos($src, 'public static function randomBytes(', $pos);
        $this->assertNotFalse($end);
        $uniqid = substr($src, $pos, $end - $pos);
        $codeOnly = preg_replace('!//.*!m', '', $uniqid) ?? $uniqid;
        $this->assertStringNotContainsString('\\unpack(', $codeOnly);
        $this->assertStringNotContainsString('unpack(', $codeOnly);
        $this->assertStringContainsString('ord($rnd[0])', $uniqid);
    }

    public function testUniqidMoreEntropyReturnsPrefixAndCore(): void
    {
        $id = \PHPCompiler\ext\standard\VmString::uniqid('p', true);
        $this->assertStringStartsWith('p', $id);
        $this->assertGreaterThan(20, strlen($id));
    }
}
