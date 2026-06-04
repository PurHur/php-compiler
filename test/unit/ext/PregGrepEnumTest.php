<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\preg_grep;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** preg_grep() enum case array values must Error like Zend (#5639). */
final class PregGrepEnumTest extends TestCase
{
    public function testEnumCaseValuesThrowError(): void
    {
        $runtime = new Runtime();
        $fn = new preg_grep();
        $enumClass = new ClassEntry('E');
        $enumClass->isEnum = true;
        $enumClass->backedType = 'string';

        $backingA = new VMVariable();
        $backingA->string('foo');
        $caseA = EnumCaseSupport::createCase($enumClass, 'A', $backingA);

        $backingB = new VMVariable();
        $backingB->string('bar');
        $caseB = EnumCaseSupport::createCase($enumClass, 'B', $backingB);

        $ht = new HashTable();
        $ht->append($caseA);
        $ht->append($caseB);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Object of class E could not be converted to string');
        $this->runPregGrep($fn, $runtime, $ht);
    }

    private function runPregGrep(Internal $fn, Runtime $runtime, HashTable $input): void
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $pattern = new VMVariable();
        $pattern->string('/^f/');
        $array = new VMVariable();
        $array->array($input);
        $frame->calledArgs = [$pattern, $array];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
    }
}
