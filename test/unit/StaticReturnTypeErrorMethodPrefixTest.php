<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #29913 — `: static` return TypeError includes Class::method(): prefix
 */
final class StaticReturnTypeErrorMethodPrefixTest extends TestCase
{
    public function testStaticReturnTypeErrorIncludesMethodPrefix(): void
    {
        $code = file_get_contents(
            dirname(__DIR__).'/repro/maintainer_gap_static_return_typeerror_omits_method.php'
        );
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_29913.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame(
            "msg:B::make(): Return value must be of type B, A returned\n",
            $out
        );
    }
}
