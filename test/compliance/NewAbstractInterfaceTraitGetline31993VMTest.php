<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: new Abstract/Interface/Trait Error getLine() at new expression (#31993).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class NewAbstractInterfaceTraitGetline31993VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'new_abstract_interface_trait_getline_31993.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/new_abstract_interface_trait_getline_31993.phpt',
            'new_abstract_interface_trait_getline_31993.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
