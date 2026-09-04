<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — AOT: LoggerTrait abstract `$this->log()` + runtime is_subclass_of parent.
 *
 * php-src: Zend/zend_compile.c zend_compile_traits; zend_object_handlers.c zend_std_get_method;
 * Zend/zend_builtin_functions.c zend_is_class_or_interface
 *
 * @group aot
 */
final class Issue36382LoggerTraitAbstractLogAotTest extends TestCase
{
    public function testLoggerTraitEmergencyDispatchesToSubclassLog(): void
    {
        $this->assertAotEchoes('test/repro/issue_36382_logger_trait_abstract_log.php', 'ok');
    }

    public function testIsSubclassOfRuntimeParentForeachKey(): void
    {
        $this->assertAotEchoes('test/repro/issue_36382_is_subclass_of_runtime_parent.php', 'ok');
    }

    private function assertAotEchoes(string $relSrc, string $expected): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/'.$relSrc;
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'logger36382_');
        $this->assertNotFalse($out);
        @unlink($out);
        $cmd = sprintf(
            'php -d memory_limit=512M %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        exec($cmd, $lines, $ec);
        $this->assertSame(0, $ec, implode("\n", $lines));
        $this->assertFileExists($out);
        exec(escapeshellarg($out).' 2>&1', $runLines, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runLines));
        $this->assertSame($expected, trim(implode("\n", $runLines)));
    }
}
