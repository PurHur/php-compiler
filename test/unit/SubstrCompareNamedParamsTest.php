<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class SubstrCompareNamedParamsTest extends TestCase
{
    public function testNamedOffsetAndLength(): void
    {
        $code = <<<'PHP'
<?php
var_export(substr_compare('abc', 'ab', offset: 0, length: 2));
echo "\n";
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'substr_compare_named.php'));
        self::assertSame("0\n", ob_get_clean());
    }
}
