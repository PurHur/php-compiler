<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** lchown/lchgrp/chroot ArgumentCountError wording matches Zend (#30568). */
final class Issue30568LchownChrootExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30568_lchown_chroot_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30568_lchown_chroot_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'lchown_hi:ArgumentCountError:lchown() expects exactly 2 arguments, 3 given',
            'lchown_lo:ArgumentCountError:lchown() expects exactly 2 arguments, 1 given',
            'lchgrp_hi:ArgumentCountError:lchgrp() expects exactly 2 arguments, 3 given',
            'lchgrp_lo:ArgumentCountError:lchgrp() expects exactly 2 arguments, 1 given',
            'chroot_hi:ArgumentCountError:chroot() expects exactly 1 argument, 2 given',
            'chroot_lo:ArgumentCountError:chroot() expects exactly 1 argument, 0 given',
            'ok_lchown:1',
            'ok_lchgrp:1',
            'ok_chroot_skipped:1',
        ] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
        $this->assertStringNotContainsString('requires exactly', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
