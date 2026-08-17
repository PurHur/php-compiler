<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: typed by-ref param zend_verify_arg_type (#31882).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class TypedByrefParamRecv31882VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'typed_byref_param_recv_31882.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_byref_param_recv_31882.phpt',
            'typed_byref_param_recv_31882.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
