<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Split-TU JIT Concerns that reference {@code CoreFunc\Internal} must import
 * {@code use PHPCompiler\Func as CoreFunc} — without it, {@code instanceof}
 * resolves to the nonexistent {@code PHPCompiler\CoreFunc\Internal} and is
 * always false, so ZEND_SEND_REF undef-skip / by-ref assigned marks never run
 * (j08_preg false "Undefined variable $m", #36081 / #36379).
 *
 * Peer: {@see CallArgConcernOpCodeImport36382Test}.
 *
 * @coversNothing
 */
final class JitConcernCoreFuncImportTest extends TestCase
{
    public function testConcernsReferencingCoreFuncImportPhpCompilerFuncAlias(): void
    {
        $dir = dirname(__DIR__, 2).'/lib/JIT/Concern';
        $missing = [];
        foreach (glob($dir.'/*.php') ?: [] as $path) {
            $src = (string) file_get_contents($path);
            if (!str_contains($src, 'CoreFunc\\')) {
                continue;
            }
            if (!str_contains($src, 'use PHPCompiler\\Func as CoreFunc;')) {
                $missing[] = basename($path);
            }
        }
        $this->assertSame(
            [],
            $missing,
            'JIT Concerns with CoreFunc\\* missing use PHPCompiler\\Func as CoreFunc'
        );
    }
}
