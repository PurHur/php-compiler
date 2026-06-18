<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Return ?? throw — non-throwing branch must yield LHS (#9447, zend_compile.c). */
final class ThrowCoalesceReturnTest extends TestCase
{
    public function testReturnCoalesceThrowNonThrowingBranch(): void
    {
        $code = <<<'PHP'
<?php
function f(?int $x): int {
    return $x ?? throw new Exception('e');
}
try {
    f(null);
} catch (Throwable $e) {
    echo 'caught';
}
echo f(1);
PHP;
        $this->assertSame('caught1', $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'throw_coalesce_return.php');
        ob_start();
        $rt->run($block);

        return ob_get_clean();
    }
}
