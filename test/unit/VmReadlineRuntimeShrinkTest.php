<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReadline;
use PHPUnit\Framework\TestCase;

/** VmReadline must not delegate to host ext/readline (#8028, #6216 phase 2). */
final class VmReadlineRuntimeShrinkTest extends TestCase
{
    public function testVmReadlineDoesNotDelegateToHostReadline(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/VmReadline.php');
        $this->assertStringNotContainsString("function_exists('readline", $source);
        $this->assertStringNotContainsString('\\readline(', $source);
        $this->assertStringNotContainsString('\\readline_add_history(', $source);
        $this->assertStringNotContainsString('\\readline_list_history(', $source);
        $this->assertStringNotContainsString('\\readline_info(', $source);
    }

    public function testInMemoryHistoryRoundtrip(): void
    {
        VmReadline::clearHistory();
        $this->assertTrue(VmReadline::addHistory('alpha'));
        $this->assertTrue(VmReadline::addHistory('beta'));
        $hist = VmReadline::listHistory();
        $this->assertSame(2, $hist->getNumElements());
        $this->assertSame('alpha', $hist->findIndex(0)?->resolveIndirect()->toString());
        $this->assertSame('beta', $hist->findIndex(1)?->resolveIndirect()->toString());
        VmReadline::clearHistory();
    }

    public function testInfoFallbackSetGet(): void
    {
        VmReadline::info('readline_name', 'phpc_test', true);
        $this->assertSame('phpc_test', VmReadline::info('readline_name'));
        $all = VmReadline::info();
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $all);
    }
}
