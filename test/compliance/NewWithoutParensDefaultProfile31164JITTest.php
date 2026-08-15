<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: `new Class()->m()` parse-error on default 8.2 advertisement (#31164). */
final class NewWithoutParensDefaultProfile31164JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'new_without_parens_default_profile.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/new_without_parens_default_profile.phpt',
            'new_without_parens_default_profile.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
