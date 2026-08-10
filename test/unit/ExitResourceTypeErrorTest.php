<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #29594 */
final class ExitResourceTypeErrorTest extends TestCase
{
    public function testExitResourceTypeErrorUsesLowercaseResourceUnderPhp84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $this->assertTrue(CompilerVersion::supportsExitFunctionForm());
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
error_reporting(E_ALL);
$r = fopen('php://memory', 'r');
try {
    exit($r);
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'exit_resource_84.php'));
            $out = ob_get_clean();
            $this->assertSame(
                "TypeError: exit(): Argument #1 (\$status) must be of type string|int, resource given\n",
                $out
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
