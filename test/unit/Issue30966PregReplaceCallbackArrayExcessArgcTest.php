<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * preg_replace_callback_array() excess argc → Zend at-most ArgumentCountError (#30966).
 *
 * php-src: ext/pcre/php_pcre.c PHP_FUNCTION(preg_replace_callback_array)
 */
final class Issue30966PregReplaceCallbackArrayExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsAtMostArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30966_preg_replace_callback_array_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30966_preg_replace_callback_array_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "excess:ArgumentCountError:preg_replace_callback_array() expects at most 5 arguments, 6 given\n"
            ."ok:1\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('this compiler build', $out);
    }
}
