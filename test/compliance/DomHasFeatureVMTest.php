<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: hasFeature dom_has_feature (ext/dom/php_dom.c) (#32491).
 */
final class DomHasFeatureVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_hasfeature.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_hasfeature.phpt',
            'dom_hasfeature.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
