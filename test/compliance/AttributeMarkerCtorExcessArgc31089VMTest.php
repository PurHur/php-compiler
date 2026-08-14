<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: built-in attribute marker __construct excess argc → ArgumentCountError (#31089). */
final class AttributeMarkerCtorExcessArgc31089VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_attribute_marker_ctor_31089.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/attributes/excess_argc_attribute_marker_ctor_31089.phpt',
            'excess_argc_attribute_marker_ctor_31089.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
