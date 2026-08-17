<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/ClassMemberConstVMTest.php';

/**
 * VM compliance: circular class constants — Zend self-referencing Error (#31837).
 *
 * php-src: Zend/zend_constants.c — IS_CONSTANT_VISITED_MARK.
 */
class ClassConstSelfReferencingCycleVMTest extends ClassMemberConstVMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'class_const_self_referencing_cycle.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/class_const_self_referencing_cycle.phpt',
            'class_const_self_referencing_cycle.phpt'
        );
    }
}
