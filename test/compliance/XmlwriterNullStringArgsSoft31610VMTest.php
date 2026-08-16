<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: XMLWriter string args (null) soft-null DEP + empty-name ValueError (#31610). */
final class XmlwriterNullStringArgsSoft31610VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'null_string_args_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/xmlwriter/null_string_args_soft.phpt',
            'null_string_args_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
