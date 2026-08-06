<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for ReflectionConstant::getAttributes PROFILE≥8.5 gate (#28157).
 *
 * Loaded via dedicated provider so a broken sibling .phpt under cases/dom/ cannot
 * abort the full VMTest data provider before these cases run.
 */
final class ReflectionConstantGetAttributesVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'reflection_constant_getattributes_phantom_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/reflection/reflection_constant_getattributes_phantom_84.phpt',
            'reflection_constant_getattributes_phantom_84.phpt'
        );
        yield 'reflection_constant_getattributes_forward_85.phpt' => self::parsePHPT(
            __DIR__.'/cases/reflection/reflection_constant_getattributes_forward_85.phpt',
            'reflection_constant_getattributes_forward_85.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
