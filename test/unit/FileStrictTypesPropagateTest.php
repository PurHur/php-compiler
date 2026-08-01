<?php

declare(strict_types=1);

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * File-level declare(strict_types=1) must mark nested Funcs (#26428/#26431, zend_compile.c).
 */
final class FileStrictTypesPropagateTest extends TestCase
{
    public function testClassMethodInheritsFileStrictTypes(): void
    {
        $runtime = new Runtime();
        $script = $runtime->parse(
            <<<'PHP'
            <?php
            declare(strict_types=1);
            class C {
                public function __isset(string $n): bool { return 1; }
                public function __get(string $n): int { return "42"; }
                public function foo(): bool { return 1; }
            }
            function top(): bool { return 1; }
            $c = function (): bool { return 1; };
            $a = fn (): bool => 1;
            PHP,
            'file_strict_propagate.php'
        );

        $this->assertTrue($script->main->strictTypes);
        $names = [];
        foreach ($script->functions as $func) {
            $names[] = $func->name;
            $this->assertTrue(
                (bool) $func->strictTypes,
                "Func {$func->name} must inherit file-level strict_types"
            );
        }
        $this->assertContains('__isset', $names);
        $this->assertContains('__get', $names);
        $this->assertContains('foo', $names);
        $this->assertContains('top', $names);
    }

    public function testWeakFileLeavesNestedFuncsNonStrict(): void
    {
        $runtime = new Runtime();
        $script = $runtime->parse(
            <<<'PHP'
            <?php
            class C {
                public function __isset(string $n): bool { return 1; }
                public function __get(string $n): int { return "42"; }
            }
            PHP,
            'file_weak_propagate.php'
        );

        $this->assertFalse($script->main->strictTypes);
        foreach ($script->functions as $func) {
            $this->assertFalse(
                (bool) $func->strictTypes,
                "Func {$func->name} must stay weak without file-level strict_types"
            );
        }
    }
}
