<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * idate() excess argc → ArgumentCountError (#30543).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(idate)
 */
final class Issue30543IdateExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = <<<'PHP'
<?php
try {
    var_export(idate('Y', time(), 1));
    echo "\nNO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    idate();
    echo "NO_THROW0\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo idate('Y', strtotime('2020-06-15 12:00:00')), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'idate_excess_argc_30543.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "idate() expects at most 2 arguments, 3 given\n"
            ."idate() expects at least 1 argument, 0 given\n"
            ."2020\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
    }
}
