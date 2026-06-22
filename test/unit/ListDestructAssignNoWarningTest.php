<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** list() / [] destructuring must not warn on assign targets when RHS is non-array (#10591). */
final class ListDestructAssignNoWarningTest extends TestCase
{
    public function testVmLeavesTargetsNullWithoutUndefinedWarnings(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$warnings = [];
set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
list($e) = $s = 'x';
[[$f]] = 'y';
list($a, $b) = 'ab';
echo count($warnings), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'list_destruct_no_warning.php'));
        self::assertSame("0\n", ob_get_clean());
    }
}
