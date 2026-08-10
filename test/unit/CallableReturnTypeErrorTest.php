<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #29887 — `: callable` return rejects non-callables (zend_verify_return_type)
 */
final class CallableReturnTypeErrorTest extends TestCase
{
    public function testBareCallableReturnTypeErrorMatchesZend(): void
    {
        $code = file_get_contents(dirname(__DIR__) . '/repro/issue_29887_callable_return.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_29887.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame(
            "ok:TypeError:expect_callable(): Return value must be of type callable, int returned\n",
            $out
        );
    }

    public function testCallableReturnAcceptsFunctionName(): void
    {
        $code = <<<'PHP'
<?php
function ok(): callable { return 'strlen'; }
echo ok()('xy');
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_29887_ok.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('2', $out);
    }
}
