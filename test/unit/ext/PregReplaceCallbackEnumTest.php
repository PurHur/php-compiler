<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\preg_replace_callback;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** preg_replace_callback() enum case $subject must TypeError like Zend (#7205). */
final class PregReplaceCallbackEnumTest extends TestCase
{
    public function testEnumCaseSubjectThrowsTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new preg_replace_callback();
        $enumClass = new ClassEntry('E');
        $enumClass->isEnum = true;
        $enumClass->backedType = 'string';

        $backing = new VMVariable();
        $backing->string('x');
        $case = EnumCaseSupport::createCase($enumClass, 'A', $backing);

        $callback = new VMVariable();
        $callback->string('strlen');

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            'preg_replace_callback(): Argument #3 ($subject) must be of type array|string, E given'
        );
        $this->runPregReplaceCallback($fn, $runtime, $callback, $case);
    }

    private function runPregReplaceCallback(
        Internal $fn,
        Runtime $runtime,
        VMVariable $callback,
        VMVariable $subject
    ): void {
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->vmContext = $runtime->vmContext;
        $pattern = new VMVariable();
        $pattern->string('/x/');
        $frame->calledArgs = [$pattern, $callback, $subject];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
    }
}
