<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * class_alias() of internal classes is profile-gated (#29150, re-#29084).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias)
 */
final class ClassAliasInternalProfile29150Test extends TestCase
{
    private const VALUE_ERROR =
        'ValueError:class_alias(): Argument #1 ($class) must be a user-defined class name, internal class name given';

    public function testVmRejectsInternalOnProfile82(): void
    {
        $lines = $this->runReproLines('bin/vm.php', '8.2');
        $this->assertSame(
            [self::VALUE_ERROR, self::VALUE_ERROR, self::VALUE_ERROR, 'true'],
            $lines
        );
    }

    public function testJitRejectsInternalOnProfile82(): void
    {
        $lines = $this->runReproLines('bin/jit.php', '8.2');
        $this->assertSame(
            [self::VALUE_ERROR, self::VALUE_ERROR, self::VALUE_ERROR, 'true'],
            $lines
        );
    }

    public function testVmAllowsInternalOnProfile84(): void
    {
        $lines = $this->runReproLines('bin/vm.php', '8.4');
        $this->assertSame(['true', 'true', 'true', 'true'], $lines);
    }

    /**
     * @return list<string>
     */
    private function runReproLines(string $bin, string $profile): array
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_29150_class_alias_internal_profile82.php';
        $cmd = 'PHP_COMPILER_PROFILE='.escapeshellarg($profile).' '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/'.$bin)
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $lines = [];
        foreach ($out as $line) {
            if (str_starts_with($line, 'PHP Deprecated:')) {
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
