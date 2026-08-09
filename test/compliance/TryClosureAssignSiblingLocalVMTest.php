<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: try-block assign of closure call result writes target CV (#29482). */
final class TryClosureAssignSiblingLocalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'try_closure_assign_sibling_local.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/try_closure_assign_sibling_local.phpt',
            'try_closure_assign_sibling_local.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
