<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: final on promoted ctor param is Parse error on ≤8.3, compile fatal on 8.4 (#31153).
 *
 * Dedicated provider — full JITTest discovery is heavy, and path-slash data-set
 * names break --filter.
 */
final class FinalPromotedCtorParamParse31153JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'final_promoted_ctor_param_reject_profile82.phpt',
            'final_promoted_ctor_param_reject_reference_profile.phpt',
            'eval_final_promo_parse_error_catchable_profile82.phpt',
            'final_promoted_ctor_param_reject_profile84.phpt',
            'final_promoted_ctor_param_accept_profile85.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
