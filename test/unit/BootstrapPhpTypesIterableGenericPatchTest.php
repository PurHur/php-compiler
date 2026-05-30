<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: php-types must accept iterable<T> docblocks on the self-host spine.
 */
final class BootstrapPhpTypesIterableGenericPatchTest extends TestCase
{
    public function testIterableGenericPatchApplied(): void
    {
        $typeFile = dirname(__DIR__, 2).'/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php';
        $this->assertFileExists($typeFile);
        $content = (string) file_get_contents($typeFile);
        $this->assertStringContainsString(
            "preg_match('/^(list|array|iterable)\\s*</i",
            $content,
            'Run script/apply-patches.sh (php-types-iterable-generic.patch) before CI'
        );
    }
}
