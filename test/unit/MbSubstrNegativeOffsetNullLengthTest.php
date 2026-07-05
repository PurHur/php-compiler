<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\mbstring\VmMbstring;
use PHPUnit\Framework\TestCase;

/** mb_substr/mb_strcut negative offset + null length (#16481, ext/mbstring/mbstring.c). */
final class MbSubstrNegativeOffsetNullLengthTest extends TestCase
{
    public function testMbSubstrNegativeOffsetWithNullLength(): void
    {
        $this->assertSame('βγ', VmMbstring::substr('αβγ', -2, null, 'UTF-8'));
    }

    public function testMbStrcutNegativeOffsetWithNullLength(): void
    {
        $this->assertSame('ト', VmMbstring::strcut('日本語テスト', -3, null, 'UTF-8'));
    }
}
