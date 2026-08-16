<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * setcookie/setrawcookie excess argc → Zend ArgumentCountError wording (#30713).
 *
 * php-src: ext/standard/head.c — PHP_FUNCTION(setcookie) / setrawcookie
 */
final class Issue30713SetcookieExcessArgcTest extends TestCase
{
    public function testVmExcessArgcMessagesMatchZend(): void
    {
        $path = __DIR__.'/../repro/issue_30713_setcookie_excess_argc.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30713_setcookie_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            'pos:ArgumentCountError:setcookie() expects at most 7 arguments, 8 given'."\n"
            .'raw:ArgumentCountError:setrawcookie() expects at most 7 arguments, 8 given'."\n"
            .'opts:ArgumentCountError:setcookie(): Expects exactly 3 arguments when argument #3 ($expires_or_options) is an array'."\n"
            .'rawopts:ArgumentCountError:setrawcookie(): Expects exactly 3 arguments when argument #3 ($expires_or_options) is an array'."\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('accepts at most seven', $out);
        $this->assertStringNotContainsString('expects at most 3 arguments when argument #3 is an array', $out);
    }
}
