<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ctype_* ArgumentCountError wording matches Zend (#30602).
 *
 * php-src: ext/ctype/ctype.c
 */
final class Issue30602CtypeExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30602_ctype_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30602_ctype_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'ctype_alnum',
            'ctype_digit',
            'ctype_alpha',
            'ctype_space',
            'ctype_xdigit',
        ] as $f) {
            $this->assertStringContainsString(
                $f.' excess:ArgumentCountError:'.$f.'() expects exactly 1 argument, 2 given',
                $out
            );
            $this->assertStringContainsString(
                $f.' missing:ArgumentCountError:'.$f.'() expects exactly 1 argument, 0 given',
                $out
            );
        }
        $this->assertStringContainsString('ok=1', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
