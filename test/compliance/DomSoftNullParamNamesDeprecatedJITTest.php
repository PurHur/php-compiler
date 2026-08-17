<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DOM soft-null deprecation cites Zend stub param names (#31824). */
final class DomSoftNullParamNamesDeprecatedJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_soft_null_param_names_deprecated.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_soft_null_param_names_deprecated.phpt',
            'dom_soft_null_param_names_deprecated.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
