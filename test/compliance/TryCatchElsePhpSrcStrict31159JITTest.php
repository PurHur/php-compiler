<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: try/catch/else is a Zend Parse error on all php-src-strict profiles (#31159).
 *
 * Dedicated provider — full JITTest discovery is heavy, and path-slash data-set
 * names break --filter.
 */
final class TryCatchElsePhpSrcStrict31159JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'try_catch_else_reference_profile.phpt',
            'try_catch_else_reference_profile_84.phpt',
            'try_catch_else_reference_profile_85.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
