<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Guard var_export(Enum::cases()) / tryFrom() inline static-call args (#8747). */
final class EnumCasesVarExportTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_cases_list.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/enum_cases_list.phpt',
            'enum_cases_list.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
