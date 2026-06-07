<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\preg_filter;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** preg_filter() enum case $subject must TypeError like Zend (#7154). */
final class PregFilterEnumTest extends TestCase
{
    public function testEnumCaseSubjectThrowsTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new preg_filter();
        $enumClass = new ClassEntry('Color');
        $enumClass->isEnum = true;
        $enumClass->backedType = 'string';

        $backing = new VMVariable();
        $backing->string('red');
        $case = EnumCaseSupport::createCase($enumClass, 'Red', $backing);

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            'preg_filter(): Argument #3 ($subject) must be of type array|string, Color given'
        );
        $this->runPregFilter($fn, $runtime, $case);
    }

    private function runPregFilter(Internal $fn, Runtime $runtime, VMVariable $subject): void
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $pattern = new VMVariable();
        $pattern->string('/red/');
        $replacement = new VMVariable();
        $replacement->string('x');
        $frame->calledArgs = [$pattern, $replacement, $subject];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
    }
}
