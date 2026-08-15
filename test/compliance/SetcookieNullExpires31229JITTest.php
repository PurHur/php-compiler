<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: setcookie null $expires_or_options under strict_types → TypeError (#31229).
 */
final class SetcookieNullExpires31229JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'setcookie_null_expires_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/setcookie_null_expires_strict_jit.phpt',
            'setcookie_null_expires_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
