<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #29532 — `$a[new T()] = …` inside try must TypeError like Zend
 * (zend_hash.c Illegal offset type); __toString must not run.
 */
final class IllegalOffsetObjectKeyNewInTryTest extends TestCase
{
    public function testObjectKeyNewInsideTryThrowsIllegalOffsetType(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/illegal_offset_object_key_new_in_try_29532.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'illegal_offset_object_key_new_in_try_29532.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "TypeError: Illegal offset type\n"
            ."TypeError: Illegal offset type\n"
            ."var TypeError: Illegal offset type\n"
            ."done\n",
            $output
        );
        $this->assertStringNotContainsString('TOSTRING', $output);
        $this->assertStringNotContainsString('AFTER_', $output);
    }
}
