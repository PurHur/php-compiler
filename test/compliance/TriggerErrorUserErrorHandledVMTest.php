<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for trigger_error(E_USER_ERROR) handled continue + PROFILE≥8.4 deprecation (#29216).
 */
final class TriggerErrorUserErrorHandledVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'trigger_error_user_error_handled.phpt',
            'trigger_error_user_error_handled_forward84.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/stdlib/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
