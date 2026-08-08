<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for #28956 issue-body final plain property (Zend/zend_inheritance.c).
 *
 * Isolated provider — avoids full VMTest data-provider walk for a two-case lock.
 */
final class FinalPlainPropertyIssue28956VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'final_plain_property_issue_28956_84.phpt',
            'final_plain_property_issue_28956_override_84.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
