<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmJsonFormat;
use PHPUnit\Framework\TestCase;

/** json_encode() default path rejects malformed UTF-8 (#9205). */
final class VmJsonInvalidUtf8Test extends TestCase
{
    public function testInvalidUtf8WithoutFlagsReturnsFalse(): void
    {
        $encoded = VmJsonFormat::encodeExported("\xC3\x28");
        $this->assertFalse($encoded);
        $this->assertSame(5, VmJson::lastError());
        $this->assertSame(
            'Malformed UTF-8 characters, possibly incorrectly encoded',
            VmJson::lastErrorMsg()
        );
    }
}
