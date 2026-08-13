<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ArrayObject/SplFileInfo/DirectoryIterator excess argc → ArgumentCountError (#30837).
 *
 * php-src: ext/spl/spl_array.c / spl_directory.c
 */
final class Issue30837SplMethodsExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_spl_methods_excess_argc_30837.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_spl_methods_excess_argc_30837.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArrayObject::exchangeArray() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArrayObject::getIterator() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'SplFileInfo::getSize() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'DirectoryIterator::getFilename() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'DirectoryIterator::isDot() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok_append: 2', $out);
        $this->assertStringNotContainsString('ACCEPTED', $out);
    }
}
