<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: diamond trait insteadof — Required Trait wasn't added (#32130).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class TraitDiamondInsteadofRequiredNotAddedVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'trait_diamond_insteadof_required_not_added.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/trait_diamond_insteadof_required_not_added.phpt',
            'trait_diamond_insteadof_required_not_added.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
