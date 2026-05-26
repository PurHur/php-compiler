<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\htmlspecialchars_decode;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for htmlspecialchars_decode(). */
final class HtmlspecialcharsDecodeBuiltinTest extends TestCase
{
    public function testAmpersandEntity(): void
    {
        $this->assertSame('Tom & Jerry', $this->runDecode('Tom &amp; Jerry'));
    }

    public function testRoundTripWithEncode(): void
    {
        $raw = '<a>&"\'</a>';
        $encoded = \PHPCompiler\ext\standard\VmString::htmlspecialchars($raw);
        $this->assertSame($raw, $this->runDecode($encoded));
    }

    public function testEntNoquotesLeavesQuoteEntities(): void
    {
        $this->assertSame('a&quot;b&#039;c', $this->runDecode('a&quot;b&#039;c', 0));
    }

    public function testEntCompatDecodesQuotes(): void
    {
        $this->assertSame('a"b\'c', $this->runDecode('a&quot;b&#039;c', ENT_COMPAT));
    }

    private function runDecode(string $value, int $flags = ENT_QUOTES | ENT_SUBSTITUTE): string
    {
        $runtime = new Runtime();
        $fn = new htmlspecialchars_decode();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string($value);
        $frame->calledArgs = [$arg];
        if (ENT_QUOTES | ENT_SUBSTITUTE !== $flags) {
            $flagsVar = new VMVariable();
            $flagsVar->int($flags);
            $frame->calledArgs[] = $flagsVar;
        }
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->toString();
    }
}
