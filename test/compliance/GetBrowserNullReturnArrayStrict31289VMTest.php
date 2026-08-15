<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: get_browser(..., null) $return_array under strict_types → TypeError (#31289).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class GetBrowserNullReturnArrayStrict31289VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'get_browser_null_return_array_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/get_browser_null_return_array_strict.phpt',
            'get_browser_null_return_array_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
