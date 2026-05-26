<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/ClassMemberConstVMTest.php';

/**
 * VM compliance: static property + static method registry pattern (#2378).
 */
class ClassStaticRegistryVMTest extends ClassMemberConstVMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'class_static_registry.phpt' => self::parsePHPT(
            __DIR__.'/cases/classes/class_static_registry.phpt',
            'class_static_registry.phpt'
        );
    }
}
