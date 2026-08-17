<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: object stored in parent private static array keeps properties (#31937).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ParentPrivateStaticArrayObjectProperty31937VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'parent_private_static_array_object_property.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/parent_private_static_array_object_property.phpt',
            'parent_private_static_array_object_property.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
