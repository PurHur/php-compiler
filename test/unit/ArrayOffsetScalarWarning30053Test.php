<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** PROFILE≥8.3 scalar dim-read Warning wording (#30053). */
final class ArrayOffsetScalarWarning30053Test extends TestCase
{
    public function testShortFormAndBoolLiteralsUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(ErrorReporter::usesShortArrayOffsetTypeWarning());
            self::assertSame(
                'Trying to access array offset on false',
                ErrorReporter::arrayOffsetOnNonContainerMessage('false')
            );
            $false = new Variable(Variable::TYPE_BOOLEAN);
            $false->bool(false);
            self::assertSame('false', ErrorReporter::arrayOffsetTypeLabel($false));
            $true = new Variable(Variable::TYPE_BOOLEAN);
            $true->bool(true);
            self::assertSame('true', ErrorReporter::arrayOffsetTypeLabel($true));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmDimMatchesZendShapeUnderProfile84(): void
    {
        $code = <<<'PHP'
<?php
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";
    return true;
});
foreach ([false, true, 1, 1.5, null] as $a) {
    var_export($a[0]);
    echo "\n";
}
PHP;
        $path = sys_get_temp_dir().'/phpc_dim_scalar_30053.php';
        file_put_contents($path, $code);
        $cmd = 'PHP_COMPILER_PROFILE=8.4 '
            .escapeshellarg(PHP_BINARY).' -d error_reporting=E_ALL -d display_errors=1 '
            .escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php').' '
            .escapeshellarg($path).' 2>&1';
        $output = shell_exec($cmd) ?? '';
        @unlink($path);

        $this->assertStringContainsString('W:Trying to access array offset on false', $output);
        $this->assertStringContainsString('W:Trying to access array offset on true', $output);
        $this->assertStringContainsString('W:Trying to access array offset on int', $output);
        $this->assertStringContainsString('W:Trying to access array offset on float', $output);
        $this->assertStringContainsString('W:Trying to access array offset on null', $output);
        $this->assertStringNotContainsString('value of type', $output);
    }
}
