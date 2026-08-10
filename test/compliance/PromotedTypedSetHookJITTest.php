<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: promoted ctor typed set(string $v) under PROFILE=8.4 (#29673). */
final class PromotedTypedSetHookJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'promoted_typed_set_hook.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/language/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
