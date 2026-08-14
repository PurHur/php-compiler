<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: empty-string ValueError "must not be empty" on PROFILE=8.4 (#30625). */
final class Issue30625EmptyStringMustNotBeEmptyVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'issue_30625_empty_string_must_not_be_empty.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/issue_30625_empty_string_must_not_be_empty.phpt',
            'issue_30625_empty_string_must_not_be_empty.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
