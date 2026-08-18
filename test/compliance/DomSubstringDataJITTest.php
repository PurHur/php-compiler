<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: substringData (ext/dom/characterdata.c php_dom_characterdata_substring_data) (#32372).
 *
 * @group llvm
 */
final class DomSubstringDataJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_substringdata.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_substringdata.phpt',
            'dom_substringdata.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
