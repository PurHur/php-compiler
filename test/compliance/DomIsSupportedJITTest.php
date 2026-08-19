<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: isSupported dom_has_feature (ext/dom/php_dom.c) (#32480).
 *
 * @group llvm
 */
final class DomIsSupportedJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_issupported.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_issupported.phpt',
            'dom_issupported.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
