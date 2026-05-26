<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/ClassMemberConstVMTest.php';

/**
 * VM compliance: user class constant fetch from global functions (#2215).
 */
class ClassConstFetchVMTest extends ClassMemberConstVMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'class_const_fetch_user.phpt' => self::parsePHPT(
            __DIR__.'/cases/classes/class_const_fetch_user.phpt',
            'class_const_fetch_user.phpt'
        );
    }
}
