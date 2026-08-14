<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #31160 — true/false param+return TypeError actual is bool on default profile
 */
final class TrueFalseTypeErrorActual31160Test extends TestCase
{
    public function testVmDefaultProfileBoolActual(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_31160_true_false_typeerror_actual.php');
        self::assertNotFalse($code);
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'issue_31160.php');
            ob_start();
            $runtime->run($block);
            $out = (string) ob_get_clean();
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
        self::assertStringContainsString('f(): Argument #1 ($x) must be of type true, bool given', $out);
        self::assertStringContainsString('g(): Return value must be of type true, bool returned', $out);
        self::assertStringContainsString('h(): Argument #1 ($x) must be of type false, bool given', $out);
        self::assertStringContainsString('i(): Argument #1 ($x) must be of type true, int given', $out);
        self::assertStringContainsString('i(): Argument #1 ($x) must be of type true, string given', $out);
        self::assertStringNotContainsString('false given', $out);
        self::assertStringNotContainsString('true given', $out);
        self::assertStringNotContainsString('false returned', $out);
    }

    public function testVmProfile84TrueFalseActual(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_31160_true_false_typeerror_actual.php');
        self::assertNotFalse($code);
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'issue_31160.php');
            ob_start();
            $runtime->run($block);
            $out = (string) ob_get_clean();
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
        self::assertStringContainsString('f(): Argument #1 ($x) must be of type true, false given', $out);
        self::assertStringContainsString('g(): Return value must be of type true, false returned', $out);
        self::assertStringContainsString('h(): Argument #1 ($x) must be of type false, true given', $out);
        self::assertStringContainsString('i(): Argument #1 ($x) must be of type true, int given', $out);
        self::assertStringNotContainsString('bool given', $out);
        self::assertStringNotContainsString('bool returned', $out);
    }
}
