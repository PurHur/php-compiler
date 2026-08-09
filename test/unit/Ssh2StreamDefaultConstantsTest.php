<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\ssh2\Ssh2Constants;
use PHPUnit\Framework\TestCase;

/** SSH2_STREAM_* / SSH2_DEFAULT_* (#28093). */
final class Ssh2StreamDefaultConstantsTest extends TestCase
{
    public function testPeclValues(): void
    {
        self::assertSame(0, Ssh2Constants::STREAM_STDIO);
        self::assertSame(1, Ssh2Constants::STREAM_STDERR);
        self::assertSame('vanilla', Ssh2Constants::DEFAULT_TERMINAL);
        self::assertSame(80, Ssh2Constants::DEFAULT_TERM_WIDTH);
        self::assertSame(25, Ssh2Constants::DEFAULT_TERM_HEIGHT);
        self::assertSame(0, Ssh2Constants::DEFAULT_TERM_UNIT);
    }

    public function testRegisteredUnderForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            self::assertTrue($ctx->isUserConstantDefined('SSH2_STREAM_STDIO'));
            self::assertTrue($ctx->isUserConstantDefined('SSH2_STREAM_STDERR'));
            self::assertTrue($ctx->isUserConstantDefined('SSH2_DEFAULT_TERMINAL'));
            self::assertSame(0, $ctx->constants['SSH2_STREAM_STDIO']->toInt());
            self::assertSame(1, $ctx->constants['SSH2_STREAM_STDERR']->toInt());
            self::assertSame('vanilla', $ctx->constants['SSH2_DEFAULT_TERMINAL']->toString());
            self::assertSame(80, $ctx->constants['SSH2_DEFAULT_TERM_WIDTH']->toInt());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
