<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — AOT: trait `$this->abstractMethod()` via abstract composer (Psr\Log\LoggerTrait).
 *
 * php-src: Zend/zend_object_handlers.c zend_std_get_method; Zend/zend_compile.c trait flatten
 *
 * @group aot
 */
final class LoggerTraitLog36382AotTest extends TestCase
{
    public function testAbstractComposerTraitCallsConcreteSubclassLog(): void
    {
        $this->assertAotEchoes('test/repro/issue_36382_logger_trait_log.php', 'error:boom');
    }

    public function testConcreteComposerTraitStillWorks(): void
    {
        $this->assertAotEchoes('test/repro/issue_36382_logger_trait_concrete.php', 'error:boom');
    }

    private function assertAotEchoes(string $relSrc, string $expected): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/'.$relSrc;
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'loggertrait36382_');
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
