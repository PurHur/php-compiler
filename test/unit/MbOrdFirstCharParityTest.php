<?php

declare(strict_types=1);

use PHPCompiler\ext\mbstring\MbChrOrdJitHelper;
use PHPCompiler\ext\mbstring\VmMbstring;
use PHPCompiler\JIT\Builtin\StringStrpos;
use PHPUnit\Framework\TestCase;

/**
 * mb_ord() VM must match NestedJIT helper + Zend first-character decode (#34243).
 *
 * php-src: ext/mbstring/mbstring.c — php_mb_ord() via enc->to_wchar (first char only).
 */
final class MbOrdFirstCharParityTest extends TestCase
{
    public function testVmOrdMatchesJitHelperForUtf8EdgeCases(): void
    {
        $cases = [
            ['A', 65],
            ['日', 26085],
            ["\xE2\x82\xAC", 8364],
            ["\xC0\x80", false],
            ['A'."\xFF", 65],
        ];
        foreach ($cases as [$input, $expected]) {
            $vm = VmMbstring::ord($input, 'UTF-8');
            $jit = MbChrOrdJitHelper::ordArgv($input, 'UTF-8');
            $jitBoxed = StringStrpos::NOT_FOUND === $jit ? false : $jit;
            $this->assertSame($expected, $vm, 'VmMbstring::ord for '.json_encode($input));
            $this->assertSame($expected, $jitBoxed, 'MbChrOrdJitHelper for '.json_encode($input));
        }
    }
}
