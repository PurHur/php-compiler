<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: self::class / __CLASS__ in trait static methods → composing class (#26659). */
final class TraitSelfClassStaticComposingVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'self_class_static_composing.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/language/trait_self_scope/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
