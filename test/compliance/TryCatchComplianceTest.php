<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/**
 * VM compliance slice for try/catch PHPT pack (issue #2084, ci-fast --filter TryCatch).
 */
final class TryCatchComplianceTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $dir = __DIR__.'/cases/language';
        foreach (['try_catch_echo', 'try_finally_order', 'try_uncaught_exit', 'throw_in_function'] as $base) {
            $path = $dir.'/'.$base.'.phpt';
            if (!is_file($path)) {
                continue;
            }
            yield 'language/'.$base => self::parsePHPT($path, $base.'.phpt');
        }
    }
}
