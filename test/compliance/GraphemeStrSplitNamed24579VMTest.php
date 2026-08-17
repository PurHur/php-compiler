<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;

require_once __DIR__.'/../BaseTest.php';

/** VM: grapheme_str_split() Reflection + Zend named $string/$length (#24579). */
final class GraphemeStrSplitNamed24579VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'grapheme_str_split_named_24579.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/intl/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('intl extension not advertised — grapheme_* withheld (#17694)');
        }
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
