<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #29550 — resource array offsets warn and cast to int (zend_hash.c).
 */
final class ResourceArrayOffsetWarnCastTest extends TestCase
{
    public function testResourceOffsetWriteIssetUnsetMatchZendShape(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/resource_array_offset_warn_cast_29550.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'resource_array_offset_warn_cast_29550.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("set-ok\nisset-ok\nunset-ok\n", $output);
        $this->assertStringNotContainsString('TypeError', $output);
        $this->assertStringNotContainsString('Illegal offset type', $output);
    }

    public function testWarningMessageFormat(): void
    {
        $this->assertSame(
            'Resource ID#7 used as offset, casting to integer (7)',
            \PHPCompiler\VM\ResourceArrayOffsetSupport::warningMessage(7)
        );
    }
}
