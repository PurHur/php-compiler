<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\gnupg\GnupgConstants;
use PHPCompiler\ext\mailparse\MailparseConstants;
use PHPUnit\Framework\TestCase;

/** GNUPG_* + MAILPARSE_EXTRACT_* PECL constants (#28064). */
final class GnupgMailparseConstantsSurfaceTest extends TestCase
{
    public function testPeclIntegerValues(): void
    {
        self::assertSame(0x0001, GnupgConstants::SIGSUM_VALID);
        self::assertSame(0x0002, GnupgConstants::SIGSUM_GREEN);
        self::assertSame(0, GnupgConstants::SIG_MODE_NORMAL);
        self::assertSame(2, GnupgConstants::ERROR_EXCEPTION);
        self::assertSame(0, GnupgConstants::PROTOCOL_OpenPGP);
        self::assertSame(0, MailparseConstants::EXTRACT_OUTPUT);
        self::assertSame(1, MailparseConstants::EXTRACT_STREAM);
        self::assertSame(2, MailparseConstants::EXTRACT_RETURN);
    }

    public function testRegisteredWhenExplicitlyEnabled(): void
    {
        $prevG = getenv('PHP_COMPILER_ENABLE_GNUPG');
        $prevM = getenv('PHP_COMPILER_ENABLE_MAILPARSE');
        putenv('PHP_COMPILER_ENABLE_GNUPG=1');
        putenv('PHP_COMPILER_ENABLE_MAILPARSE=1');
        $_ENV['PHP_COMPILER_ENABLE_GNUPG'] = '1';
        $_ENV['PHP_COMPILER_ENABLE_MAILPARSE'] = '1';
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            self::assertTrue($ctx->isUserConstantDefined('GNUPG_SIGSUM_VALID'));
            self::assertTrue($ctx->isUserConstantDefined('GNUPG_SIG_MODE_NORMAL'));
            self::assertTrue($ctx->isUserConstantDefined('GNUPG_ERROR_EXCEPTION'));
            self::assertTrue($ctx->isUserConstantDefined('GNUPG_PROTOCOL_OpenPGP'));
            self::assertTrue($ctx->isUserConstantDefined('MAILPARSE_EXTRACT_OUTPUT'));
            self::assertTrue($ctx->isUserConstantDefined('MAILPARSE_EXTRACT_STREAM'));
            self::assertTrue($ctx->isUserConstantDefined('MAILPARSE_EXTRACT_RETURN'));
            self::assertSame(0x0001, $ctx->constants['GNUPG_SIGSUM_VALID']->toInt());
            self::assertSame(2, $ctx->constants['GNUPG_ERROR_EXCEPTION']->toInt());
            self::assertSame(0, $ctx->constants['MAILPARSE_EXTRACT_OUTPUT']->toInt());
            self::assertSame(2, $ctx->constants['MAILPARSE_EXTRACT_RETURN']->toInt());
        } finally {
            if (false === $prevG) {
                putenv('PHP_COMPILER_ENABLE_GNUPG');
                unset($_ENV['PHP_COMPILER_ENABLE_GNUPG']);
            } else {
                putenv('PHP_COMPILER_ENABLE_GNUPG='.$prevG);
                $_ENV['PHP_COMPILER_ENABLE_GNUPG'] = $prevG;
            }
            if (false === $prevM) {
                putenv('PHP_COMPILER_ENABLE_MAILPARSE');
                unset($_ENV['PHP_COMPILER_ENABLE_MAILPARSE']);
            } else {
                putenv('PHP_COMPILER_ENABLE_MAILPARSE='.$prevM);
                $_ENV['PHP_COMPILER_ENABLE_MAILPARSE'] = $prevM;
            }
        }
    }
}
