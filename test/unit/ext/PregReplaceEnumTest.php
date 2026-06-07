<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\preg_replace;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** preg_replace() enum case $subject must TypeError like Zend (#7453). */
final class PregReplaceEnumTest extends TestCase
{
    public function testEnumCaseSubjectThrowsTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new preg_replace();
        $enumClass = new ClassEntry('E');
        $enumClass->isEnum = true;
        $enumClass->backedType = 'string';

        $backing = new VMVariable();
        $backing->string('x');
        $case = EnumCaseSupport::createCase($enumClass, 'A', $backing);

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            'preg_replace(): Argument #3 ($subject) must be of type array|string, E given'
        );
        $this->runPregReplace($fn, $runtime, $case);
    }

    private function runPregReplace(Internal $fn, Runtime $runtime, VMVariable $subject): void
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $pattern = new VMVariable();
        $pattern->string('/x/');
        $replacement = new VMVariable();
        $replacement->string('y');
        $frame->calledArgs = [$pattern, $replacement, $subject];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
    }
}
