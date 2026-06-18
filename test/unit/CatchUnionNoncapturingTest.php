<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for non-capturing union catch (issue #9766). */
final class CatchUnionNoncapturingTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'catch_union_noncapturing.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/catch_union_noncapturing.phpt',
            'catch_union_noncapturing.phpt'
        );
    }
}
