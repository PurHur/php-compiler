<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for string search/span builtins (#28311).
 *
 * php-src: ext/standard/string.stub.php / string.c
 */
final class Issue28311StrFamilyExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28311.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28311.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('strcspn_ok:OK:1', $out);
        $this->assertStringContainsString('stripos_ok:OK:1', $out);
        $this->assertStringContainsString(
            'strcspn:ArgumentCountError:strcspn() expects at most 4 arguments, 5 given',
            $out
        );
        $this->assertStringContainsString(
            'strspn:ArgumentCountError:strspn() expects at most 4 arguments, 5 given',
            $out
        );
        $this->assertStringContainsString(
            'substr_count:ArgumentCountError:substr_count() expects at most 4 arguments, 5 given',
            $out
        );
        $this->assertStringContainsString(
            'stripos:ArgumentCountError:stripos() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'strripos:ArgumentCountError:strripos() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'strrpos:ArgumentCountError:strrpos() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'strpos_peer:ArgumentCountError:strpos() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
