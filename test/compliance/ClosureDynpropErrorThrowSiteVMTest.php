<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: Closure/noDynamicProperties Error file/line cite user assignment (#29457).
 */
final class ClosureDynpropErrorThrowSiteVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'closure_dynprop_error_throw_site.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_dynprop_error_throw_site.phpt',
            'closure_dynprop_error_throw_site.phpt'
        );
        yield 'closure_dynprop_error_uncaught_throw_site.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_dynprop_error_uncaught_throw_site.phpt',
            'closure_dynprop_error_uncaught_throw_site.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
