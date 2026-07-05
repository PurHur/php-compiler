<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ErrorSuppressStrictAssignTest extends TestCase
{
    public function testSuppressAssignToNamedLocalUnderStrictTypes(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$v = @get_cfg_var('display_errors');
echo is_string($v) && '' === $v ? "ok\n" : "bad\n";
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'strict_suppress_assign.php'));
        $out = ob_get_clean();

        self::assertSame("ok\n", $out);
    }

    public function testSuppressAssignVarExportUnderStrictTypes(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$v = @get_cfg_var('display_errors');
echo var_export($v, true), "\n";
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'strict_suppress_var_export.php'));
        $out = ob_get_clean();

        self::assertSame("''\n", $out);
    }
}
