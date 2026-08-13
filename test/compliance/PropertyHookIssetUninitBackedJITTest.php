<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: isset/empty/?? skip get on uninitialized same-name backed hooks (#30739).
 *
 * @group llvm
 */
require_once __DIR__.'/../BaseTest.php';

final class PropertyHookIssetUninitBackedJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'property_hook_isset_uninit_backed_forward_profile.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/property_hook_isset_uninit_backed_forward_profile.phpt',
            'property_hook_isset_uninit_backed_forward_profile.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }
}
