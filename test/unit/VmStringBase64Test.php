<?php

declare(strict_types=1);

namespace Test\Unit;

use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

final class VmStringBase64Test extends TestCase
{
    public function testEncodeDecodeRoundTrip(): void
    {
        foreach (['', 'a', 'ab', 'abc', 'hello', "\x00\xff\xfe"] as $plain) {
            self::assertSame($plain, VmString::base64_decode(VmString::base64_encode($plain)));
        }
    }

    public function testMatchesPhpSubset(): void
    {
        self::assertSame('YQ==', VmString::base64_encode('a'));
        self::assertSame('a', VmString::base64_decode('YQ=='));
        self::assertSame('', VmString::base64_decode('!!!'));
    }
}
