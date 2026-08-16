<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: error_log Reflection + Zend named params (#23341).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ErrorLogNamed23341VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'error_log_named_23341.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/error_log_named_23341.phpt',
            'error_log_named_23341.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
