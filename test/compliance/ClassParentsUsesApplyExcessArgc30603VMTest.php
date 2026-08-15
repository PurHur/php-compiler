<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: class_parents/class_uses/iterator_apply ArgumentCountError wording (#30603). */
final class ClassParentsUsesApplyExcessArgc30603VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_class_parents_uses_apply_30603.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_class_parents_uses_apply_30603.phpt',
            'excess_argc_class_parents_uses_apply_30603.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
