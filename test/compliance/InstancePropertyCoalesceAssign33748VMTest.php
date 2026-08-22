<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: ??= on uninitialized instance property stores; readback matches Zend (#33748).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class InstancePropertyCoalesceAssign33748VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'instance_property_coalesce_assign_33748.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/instance_property_coalesce_assign_33748.phpt',
            'instance_property_coalesce_assign_33748.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
