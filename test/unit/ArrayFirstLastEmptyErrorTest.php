<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ArrayFirstLastEmptyErrorTest extends TestCase
{
    public function testEmptyArrayThrowsError(): void
    {
        $code = <<<'PHP'
<?php
foreach (['array_first', 'array_last'] as $fn) {
    try {
        $fn([]);
        echo $fn, ": uncaught\n";
    } catch (Error $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'array_first_last_empty.php'));
        self::assertSame(
            "array_first: array_first(): Argument #1 (\$array) must not be empty\n"
            ."array_last: array_last(): Argument #1 (\$array) must not be empty\n",
            ob_get_clean()
        );
    }
}
