<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\html_entity_decode;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for html_entity_decode(). */
final class HtmlEntityDecodeBuiltinTest extends TestCase
{
    public function testMatchesHtmlspecialcharsDecode(): void
    {
        $encoded = '&lt;a&gt;';
        $this->assertSame(
            \PHPCompiler\ext\standard\VmString::htmlspecialchars_decode($encoded),
            $this->runDecode($encoded)
        );
    }

    public function testRoundTripWithEncode(): void
    {
        $raw = '<a>&"\'</a>';
        $encoded = \PHPCompiler\ext\standard\VmString::htmlentities($raw);
        $this->assertSame($raw, $this->runDecode($encoded));
    }

    private function runDecode(string $value, int $flags = ENT_QUOTES | ENT_SUBSTITUTE): string
    {
        $runtime = new Runtime();
        $fn = new html_entity_decode();
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
