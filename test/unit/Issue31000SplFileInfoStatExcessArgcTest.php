<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplFileInfo zero-arg stat/predicate excess argc (#31000).
 *
 * php-src: ext/spl/spl_directory.c
 */
final class Issue31000SplFileInfoStatExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31000_splfileinfo_stat_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31000_splfileinfo_stat_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'getExtension',
            'isFile',
            'isDir',
            'isLink',
            'isReadable',
            'getInode',
            'getOwner',
            'getGroup',
            'getATime',
            'getMTime',
            'getCTime',
            'getPerms',
            'getType',
        ] as $m) {
            $this->assertStringContainsString(
                $m.'=ArgumentCountError:SplFileInfo::'.$m.'() expects exactly 0 arguments, 1 given',
                $out
            );
        }
        $this->assertStringContainsString('ok=1', $out);
    }
}
