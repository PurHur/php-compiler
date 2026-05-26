<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\htmlentities;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for htmlentities(). */
final class HtmlentitiesBuiltinTest extends TestCase
{
    public function testMatchesHtmlspecialchars(): void
    {
        $raw = '<a>&"\'</a>';
        $this->assertSame(
            \PHPCompiler\ext\standard\VmString::htmlspecialchars($raw),
            $this->runEncode($raw)
        );
    }

    private function runEncode(string $value, int $flags = ENT_QUOTES | ENT_SUBSTITUTE): string
    {
        $runtime = new Runtime();
        $fn = new htmlentities();
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
