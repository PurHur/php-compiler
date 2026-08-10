<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #29888 — `: iterable` return TypeError uses Traversable|array (zend_verify_return_type)
 */
final class IterableReturnTypeErrorTest extends TestCase
{
    public function testBareIterableReturnTypeErrorMatchesZend(): void
    {
        $code = file_get_contents(dirname(__DIR__) . '/repro/issue_29888_iterable_return.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_29888.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame(
            "ok:TypeError:expect_iterable(): Return value must be of type Traversable|array, int returned\n",
            $out
        );
    }

    public function testIterableReturnAcceptsArrayAndTraversable(): void
    {
        $code = <<<'PHP'
<?php
function a(): iterable { return [1, 2]; }
function t(): iterable { return new ArrayIterator([3]); }
echo count(a()), ':', iterator_count(t());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_29888_ok.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame('2:1', $out);
    }
}
