<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmJsonFormat;
use PHPUnit\Framework\TestCase;

/** json_decode() UTF-16 surrogate escapes (#22821). */
final class VmJsonUtf16SurrogateTest extends TestCase
{
    public function testUnpairedHighSurrogateIsUtf16Error(): void
    {
        $decoded = VmJsonFormat::decode('"\uD800"');
        $this->assertNull($decoded);
        $this->assertSame(VmJson::ERROR_UTF16, VmJson::lastError());
        $this->assertSame(
            'Single unpaired UTF-16 surrogate in unicode escape',
            VmJson::lastErrorMsg()
        );
    }

    public function testUnpairedLowSurrogateIsUtf16Error(): void
    {
        $decoded = VmJsonFormat::decode('"\uDC00"');
        $this->assertNull($decoded);
        $this->assertSame(VmJson::ERROR_UTF16, VmJson::lastError());
    }

    public function testValidSurrogatePairDecodesToU10000(): void
    {
        $decoded = VmJsonFormat::decode('"\uD800\uDC00"');
        $this->assertSame("\xF0\x90\x80\x80", $decoded);
        $this->assertSame(0, VmJson::lastError());
    }

    public function testHighFollowedByNonLowIsUtf16Error(): void
    {
        $decoded = VmJsonFormat::decode('"\uD800\uD800"');
        $this->assertNull($decoded);
        $this->assertSame(VmJson::ERROR_UTF16, VmJson::lastError());
    }

    public function testUtf16ErrorOnObjectKeyPreserved(): void
    {
        $decoded = VmJsonFormat::decode('{"\uD800":1}');
        $this->assertNull($decoded);
        $this->assertSame(VmJson::ERROR_UTF16, VmJson::lastError());
    }
}
