<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_unique() Reflection $flags default SORT_STRING (#27949).
 *
 * php-src: ext/standard/array.stub.php — int $flags = SORT_STRING
 */
final class Issue27949ArrayUniqueFlagsDefaultTest extends TestCase
{
    public function testBuiltinInternalDefaultValuesFlagsIsSortString(): void
    {
        $info = ['name' => 'flags', 'type' => 'int', 'isOptional' => true];
        $this->assertTrue(BuiltinInternalDefaultValues::isAvailable('array_unique', 1, $info, false));
        $dest = new Variable();
        $this->assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'array_unique', 1, $info));
        $this->assertSame(2, $dest->toInt());
    }

    public function testVmReflectionDefaultAndOmittedFlagsRuntime(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_27949_array_unique_flags_default.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_27949_array_unique_flags_default.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "default:2\nSORT_STRING:2\nruntime:2\n",
            $out
        );
    }
}
