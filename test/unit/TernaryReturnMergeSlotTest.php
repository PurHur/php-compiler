<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class TernaryReturnMergeSlotTest extends TestCase
{
    public function testReturnTernaryTrueBranchValue(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php declare(strict_types=1); function f(bool $flag): int|true { return $flag ? 1 : true; } echo f(true), "\\n", f(false) === true ? "ok" : "no", "\\n";',
            'ternary_return.php'
        );
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame("1\nok\n", $out);
    }

    public function testNullableStringTernaryElseArmValue(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php declare(strict_types=1); function f(?string $name): ?string { return null === $name ? null : $name; } echo f(null) === null ? "null" : "bad", "\\n", f("hello"), "\\n";',
            'ternary_nullable_else.php'
        );
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame("null\nhello\n", $out);
    }

    public function testNullableStringTernaryIfArmValue(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php declare(strict_types=1); function f(?string $name): ?string { return null !== $name ? $name : null; } echo f("hello"), "\\n";',
            'ternary_nullable_if.php'
        );
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame("hello\n", $out);
    }
}
