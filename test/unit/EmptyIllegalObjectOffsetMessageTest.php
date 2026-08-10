<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #29549 — empty($arr[$object]) must TypeError with isset/empty wording.
 */
final class EmptyIllegalObjectOffsetMessageTest extends TestCase
{
    public function testEmptyObjectOffsetMatchesIssetMessage(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/empty_obj_key_29549.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'empty_obj_key_29549.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "TypeError:Illegal offset type in isset or empty\n"
            ."TypeError:Illegal offset type in isset or empty\n",
            $output
        );
    }
}
