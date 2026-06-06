<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for zend_thread_id() (#6870). */
final class ZendThreadIdVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'zend_thread_id.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/zend_thread_id.phpt',
            'zend_thread_id.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
