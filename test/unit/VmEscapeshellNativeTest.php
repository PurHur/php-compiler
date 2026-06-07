<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmEscapeshell;
use PHPUnit\Framework\TestCase;

/**
 * @group vm_escapeshell_native
 */
final class VmEscapeshellNativeTest extends TestCase
{
    public function test_escapeshellarg_matches_zend(): void
    {
        if (!\function_exists('escapeshellarg')) {
            self::markTestSkipped('host escapeshellarg unavailable');
        }

        foreach (["it's a test", '', 'plain', 'no spaces'] as $sample) {
            self::assertSame(\escapeshellarg($sample), VmEscapeshell::escapeshellarg($sample), $sample);
        }
    }

    public function test_escapeshellcmd_matches_zend(): void
    {
        if (!\function_exists('escapeshellcmd')) {
            self::markTestSkipped('host escapeshellcmd unavailable');
        }

        foreach ([
            'echo hello; rm -rf /',
            'a|b',
            'a&b',
            '`x`',
            '$HOME',
            'a>b',
            'a<b',
            'plain',
            '',
        ] as $sample) {
            self::assertSame(\escapeshellcmd($sample), VmEscapeshell::escapeshellcmd($sample), $sample);
        }
    }
}
