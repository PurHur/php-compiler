<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\pcntl\PcntlConstants;
use PHPUnit\Framework\TestCase;

/** Issue #26759 — SIGPOLL/SIGBABY + ILL_* registered like php-src ext/pcntl/pcntl.stub.php. */
final class PcntlSigIllConstantsTest extends TestCase
{
    public function testSigPollBabyAndIllConstantsRegistered(): void
    {
        $c = PcntlConstants::registeredConstants();

        self::assertSame(29, $c['SIGPOLL']);
        self::assertSame(29, $c['SIGIO']);
        self::assertSame(31, $c['SIGBABY']);
        self::assertSame(31, $c['SIGSYS']);

        self::assertSame(1, $c['ILL_ILLOPC']);
        self::assertSame(2, $c['ILL_ILLOPN']);
        self::assertSame(3, $c['ILL_ILLADR']);
        self::assertSame(4, $c['ILL_ILLTRP']);
        self::assertSame(5, $c['ILL_PRVOPC']);
        self::assertSame(6, $c['ILL_PRVREG']);
        self::assertSame(7, $c['ILL_COPROC']);
        self::assertSame(8, $c['ILL_BADSTK']);
    }
}
