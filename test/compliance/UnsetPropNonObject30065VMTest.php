<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: unset property on non-object — Zend ZEND_UNSET_OBJ silent no-op (#30065).
 */
require_once __DIR__.'/../BaseTest.php';

final class UnsetPropNonObject30065VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'unset_prop_non_object_30065.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/unset_prop_non_object_30065.phpt',
            'unset_prop_non_object_30065.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
