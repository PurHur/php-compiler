<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM: static private Error names fetch class (#29524). */
class StaticSelfPrivateErrorNamesFetchClassVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/static_self_private_error_names_fetch_class.phpt';
        yield 'static_self_private_error_names_fetch_class' => self::parsePHPT(
            $path,
            'static_self_private_error_names_fetch_class.phpt'
        );
    }
}
