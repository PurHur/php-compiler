<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: user_error/trigger_error null $error_level soft Deprecated + ValueError (#31464).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class UserErrorNullLevelSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'user_error_null_level_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/user_error_null_level_soft.phpt',
            'user_error_null_level_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
