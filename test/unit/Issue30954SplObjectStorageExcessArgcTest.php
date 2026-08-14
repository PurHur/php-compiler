<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplObjectStorage attach/contains/detach/setInfo excess argc (#30954).
 *
 * php-src: ext/spl/spl_observer.c
 */
final class Issue30954SplObjectStorageExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30954_splobjectstorage_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30954_splobjectstorage_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'attach: ArgumentCountError: SplObjectStorage::attach() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'contains: ArgumentCountError: SplObjectStorage::contains() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'detach: ArgumentCountError: SplObjectStorage::detach() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'setInfo: ArgumentCountError: SplObjectStorage::setInfo() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString('attach_ok: OK', $out);
        $this->assertStringContainsString('contains_ok: OK', $out);
        $this->assertStringContainsString('setInfo_ok: OK', $out);
        $this->assertStringContainsString('detach_ok: OK', $out);
        $this->assertStringContainsString('after_detach=0', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
