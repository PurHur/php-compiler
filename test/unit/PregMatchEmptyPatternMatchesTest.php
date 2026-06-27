<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class PregMatchEmptyPatternMatchesTest extends TestCase
{
    public function testEmptyPatternSetsMatchesNull(): void
    {
        $code = <<<'PHP'
<?php
preg_match('', 'x', $m);
var_export($m);
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'preg_empty.php'));
        self::assertSame('NULL', ob_get_clean());
    }

    public function testEmptyPatternMatchAllSetsMatchesNull(): void
    {
        $code = <<<'PHP'
<?php
preg_match_all('', 'x', $m);
var_export($m);
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'preg_empty_all.php'));
        self::assertSame('NULL', ob_get_clean());
    }
}
