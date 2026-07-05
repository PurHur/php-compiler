<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ArrayFirstLastEmptyErrorTest extends TestCase
{
    public function testEmptyArrayReturnsNull(): void
    {
        if (!CompilerVersion::supportsPhp84ArraySearchFunctions()) {
            $this->markTestSkipped('array_first/array_last withheld on PHP 8.2 reference profile (#14505)');
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
