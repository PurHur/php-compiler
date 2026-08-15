<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * class_parents/class_uses/iterator_apply ArgumentCountError wording matches Zend (#30603).
 *
 * php-src: ext/standard/spl_functions.c / ext/spl/php_spl.c
 */
final class Issue30603ClassParentsUsesApplyArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30603_class_parents_uses_apply_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30603_class_parents_uses_apply_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'parents_hi:ArgumentCountError:class_parents() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'parents_lo:ArgumentCountError:class_parents() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'uses_hi:ArgumentCountError:class_uses() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'uses_lo:ArgumentCountError:class_uses() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'apply_hi:ArgumentCountError:iterator_apply() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'apply_lo:ArgumentCountError:iterator_apply() expects at least 2 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok_parents:1', $out);
        $this->assertStringContainsString('ok_uses:1', $out);
        $this->assertStringContainsString('ok_apply:1', $out);
        $this->assertStringNotContainsString('requires one or two', $out);
        $this->assertStringNotContainsString('requires two or three', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
