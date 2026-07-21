<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmJsonFlags;
use PHPCompiler\ext\standard\VmJsonFormat;
use PHPUnit\Framework\TestCase;

/** json_encode() JSON_INVALID_UTF8_SUBSTITUTE / IGNORE (#9964, #21723). */
final class VmJsonUtf8SubstituteTest extends TestCase
{
    public function testSubstituteFlagEncodesReplacementCharacter(): void
    {
        $encoded = VmJsonFormat::encodeExported(
            "\xB1\x31",
            VmJsonFlags::INVALID_UTF8_SUBSTITUTE
        );
        $this->assertSame('"\ufffd1"', $encoded);
        $this->assertSame('225c75666666643122', bin2hex((string) $encoded));
    }

    public function testThrowOnErrorWithSubstituteStillEncodes(): void
    {
        $encoded = VmJsonFormat::encodeExported(
            "\xB1\x31",
            VmJsonFlags::INVALID_UTF8_SUBSTITUTE | VmJsonFlags::THROW_ON_ERROR
        );
        $this->assertSame('"\ufffd1"', $encoded);
    }

    public function testIgnoreFlagStripsMalformedBytes(): void
    {
        $encoded = VmJsonFormat::encodeExported(
            'a'."\x80".'b',
            VmJsonFlags::INVALID_UTF8_IGNORE
        );
        $this->assertSame('"ab"', $encoded);
    }

    public function testIgnoreWinsOverSubstitute(): void
    {
        $encoded = VmJsonFormat::encodeExported(
            'a'."\x80".'b',
            VmJsonFlags::INVALID_UTF8_IGNORE | VmJsonFlags::INVALID_UTF8_SUBSTITUTE
        );
        $this->assertSame('"ab"', $encoded);
    }

    public function testDecodeIgnoreStripsMalformedBytesInStringLiteral(): void
    {
        $json = '"a'."\x80".'b"';
        $decoded = VmJsonFormat::decode($json, false, 512, VmJsonFlags::INVALID_UTF8_IGNORE);
        $this->assertSame('ab', $decoded);
    }

    public function testDecodeSubstituteReplacesMalformedBytesInStringLiteral(): void
    {
        $json = '"a'."\x80".'b"';
        $decoded = VmJsonFormat::decode($json, false, 512, VmJsonFlags::INVALID_UTF8_SUBSTITUTE);
        $this->assertSame("a\xEF\xBF\xBDb", $decoded);
    }

    public function testDecodeIgnoreWinsOverSubstitute(): void
    {
        $json = '"a'."\x80".'b"';
        $flags = VmJsonFlags::INVALID_UTF8_IGNORE | VmJsonFlags::INVALID_UTF8_SUBSTITUTE;
        $decoded = VmJsonFormat::decode($json, false, 512, $flags);
        $this->assertSame('ab', $decoded);
    }
}
