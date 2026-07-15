<?php

declare(strict_types=1);

namespace test\unit;

use PHPCompiler\ext\mbstring\VmMbstring;
use PHPUnit\Framework\TestCase;

/**
 * @group mbstring
 */
final class MbSendMailTest extends TestCase
{
    public function testPrepareSendMailBuildsMimeHeaders(): void
    {
        $prepared = VmMbstring::prepareSendMail(
            'user@example.com',
            'über',
            "hello\n",
            null,
            null
        );

        self::assertSame('user@example.com', $prepared['to']);
        self::assertStringContainsString('=?', $prepared['subject']);
        self::assertStringContainsString('MIME-Version: 1.0', $prepared['headers']);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $prepared['headers']);
        self::assertStringContainsString('Content-Transfer-Encoding: base64', $prepared['headers']);
    }

    public function testNormalizeMailRecipientReplacesControlChars(): void
    {
        $prepared = VmMbstring::prepareSendMail("user\r\n example.com", 's', 'm');

        self::assertSame('userexample.com', $prepared['to']);
    }
}
