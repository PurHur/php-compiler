<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\imap\ImapMboxEngine;
use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\ext\imap\ImapMboxEngine */
final class ImapMboxEngineTest extends TestCase
{
    public function testParseFixtureAndSearch(): void
    {
        $path = dirname(__DIR__).'/fixtures/imap/tiny.mbox';
        $messages = ImapMboxEngine::parseFile($path);
        self::assertCount(2, $messages);
        self::assertSame('Hello imap', $messages[0]['headerMap']['subject']);
        self::assertStringContainsString('hello imap body', $messages[0]['body']);

        $all = ImapMboxEngine::search($messages, 'ALL');
        self::assertSame([1, 2], $all);
        $subj = ImapMboxEngine::search($messages, 'SUBJECT "Second"');
        self::assertSame([2], $subj);
    }
}
