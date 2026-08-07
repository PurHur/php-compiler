<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for strstr/stristr/strchr (#28228).
 *
 * php-src: ext/standard/string.stub.php / string.c
 */
final class Issue28228StrstrFamilyExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28228.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28228.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('strstr_ok:OK:cdef', $out);
        $this->assertStringContainsString('strstr_before:OK:ab', $out);
        $this->assertStringContainsString('stristr_ok:OK:CdEf', $out);
        $this->assertStringContainsString(
            'strstr:ArgumentCountError:strstr() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'stristr:ArgumentCountError:stristr() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'strchr:ArgumentCountError:strchr() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
