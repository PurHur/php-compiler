<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ArrayFirstLastEmptyErrorTest extends TestCase
{
    public function testEmptyArrayReturnsNull(): void
    {
        if (!CompilerVersion::supportsPhp85ArrayFirstLast()) {
            $this->markTestSkipped('array_first/array_last withheld until PHP 8.5 profile (#21173)');
        }
        $code = <<<'PHP'
<?php
foreach (['array_first', 'array_last'] as $fn) {
    $v = $fn([]);
    echo $fn, ': ', var_export($v, true), "\n";
}
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'array_first_last_empty.php'));
        self::assertSame(
            "array_first: NULL\n"
            ."array_last: NULL\n",
            ob_get_clean()
        );
    }
}
