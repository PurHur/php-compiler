<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for strtok() (#3201, #5515).
 *
 * @group llvm
 * @group jit
 */
final class StrtokJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (['strtok_jit.phpt', 'strtok_null_continuation.phpt', 'strtok_null_token.phpt', 'strtok_type_error_jit.phpt'] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/stdlib/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
