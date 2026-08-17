<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: circular class constants self-referencing Error (#31837, zend_constants.c). */
final class ClassConstSelfReferencingCycle31837VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'class_const_self_referencing_cycle.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/class_const_self_referencing_cycle.phpt',
            'class_const_self_referencing_cycle.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
