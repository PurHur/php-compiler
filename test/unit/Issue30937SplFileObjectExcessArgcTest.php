<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplFileObject eof/fgets/fflush + FilesystemIterator::getFlags excess argc (#30937).
 *
 * php-src: ext/spl/spl_directory.c
 */
final class Issue30937SplFileObjectExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30937_splfileobject_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30937_splfileobject_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'eof:ArgumentCountError:SplFileObject::eof() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'fgets:ArgumentCountError:SplFileObject::fgets() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'fflush:ArgumentCountError:SplFileObject::fflush() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'flags:ArgumentCountError:FilesystemIterator::getFlags() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok=1', $out);
    }
}
