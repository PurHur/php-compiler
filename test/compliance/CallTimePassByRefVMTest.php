<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/**
 * VM compliance: call-time pass-by-reference must not compile (PHP 8+, #5354, #5505).
 */
final class CallTimePassByRefVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        foreach ([
            'call_time_pass_by_ref.phpt',
            'call_time_pass_by_ref_unshift.phpt',
        ] as $file) {
            yield preg_replace('/\.phpt$/', '', $file) ?: $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }
}
