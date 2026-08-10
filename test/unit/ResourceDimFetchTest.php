<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\ErrorReporter;
use PHPUnit\Framework\TestCase;

/**
 * Resource used as array container — Zend dim semantics (#30028).
 */
final class ResourceDimFetchTest extends TestCase
{
    public function testResourceOffsetWarningMessageUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertSame(
                'Trying to access array offset on resource',
                ErrorReporter::arrayOffsetOnResourceMessage()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmResourceDimMatchesZendShape(): void
    {
        $code = <<<'PHP'
<?php
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";
    return true;
});
$f = fopen('php://memory', 'r');
try {
    var_export($f[0]);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $f[0] = 1;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
var_export(isset($f[0]));
echo "\n";
var_export(empty($f[0]));
echo "\n";
fclose($f);
PHP;
        $path = sys_get_temp_dir().'/phpc_resource_dim_30028.php';
        file_put_contents($path, $code);
        $cmd = 'PHP_COMPILER_PROFILE=8.4 '
            .escapeshellarg(PHP_BINARY).' -d error_reporting=E_ALL -d display_errors=1 '
            .escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php').' '
            .escapeshellarg($path).' 2>&1';
        $output = shell_exec($cmd) ?? '';
        @unlink($path);

        $this->assertStringContainsString('W:Trying to access array offset on resource', $output);
        $this->assertStringContainsString("NULL\n", $output);
        $this->assertStringContainsString('Error:Cannot use a scalar value as an array', $output);
        $this->assertStringContainsString("false\ntrue\n", $output);
        $this->assertStringNotContainsString('Cannot use object of type Resource as array', $output);
    }
}
