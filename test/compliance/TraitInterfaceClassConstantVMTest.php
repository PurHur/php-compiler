<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/ClassMemberConstVMTest.php';

/** VM compliance: trait/interface inherited class constants (#9430). */
class TraitInterfaceClassConstantVMTest extends ClassMemberConstVMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'trait_interface_class_constant.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/trait_interface_class_constant.phpt',
            'trait_interface_class_constant.phpt'
        );
    }
}
