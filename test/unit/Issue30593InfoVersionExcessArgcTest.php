<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for extension_loaded/phpinfo/version_compare (#30593).
 *
 * php-src: ext/standard/info.c / versioning.c
 */
final class Issue30593InfoVersionExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30593_info_version_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30593_info_version_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "extension_loaded() expects exactly 1 argument, 2 given\n"
            ."phpinfo() expects at most 1 argument, 2 given\n"
            ."version_compare() expects at most 3 arguments, 4 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
        $this->assertStringNotContainsString('accepts at most one argument', $out);
        $this->assertStringNotContainsString('expects 2 or 3 arguments', $out);
    }

    public function testVmLegalArityStillWorks(): void
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile(<<<'PHP'
<?php
echo extension_loaded('standard') ? 'ok' : 'fail', "\n";
echo version_compare('1.0', '2.0', '<') ? 'ok' : 'fail', "\n";
PHP
            , 'issue_30593_legal.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("ok\nok\n", $out);
    }
}
