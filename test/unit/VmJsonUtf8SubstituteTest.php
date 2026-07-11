<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmJsonFlags;
use PHPCompiler\ext\standard\VmJsonFormat;
use PHPUnit\Framework\TestCase;

/** json_encode() JSON_INVALID_UTF8_SUBSTITUTE (#9964). */
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
}
