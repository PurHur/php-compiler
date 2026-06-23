<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\preg_replace;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** preg_replace() array $pattern/$replacement overload (#10808, ext/pcre/php_pcre.c). */
final class PregReplaceArrayPatternTest extends TestCase
{
    public function testArrayPatternAndReplacementOnStringSubject(): void
    {
        $runtime = new Runtime();
        $fn = new preg_replace();
        $result = $this->runPregReplace(
            $fn,
            $runtime,
            ['pattern' => ['/a/'], 'replacement' => ['A'], 'subject' => 'aba']
        );
        self::assertSame('AbA', $result);
    }

    public function testSequentialArrayPatterns(): void
    {
        $runtime = new Runtime();
        $fn = new preg_replace();
        $result = $this->runPregReplace(
            $fn,
            $runtime,
            ['pattern' => ['/a/', '/b/'], 'replacement' => ['A', 'B'], 'subject' => 'aba']
        );
        self::assertSame('ABA', $result);
    }

    public function testStringPatternWithArrayReplacementTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new preg_replace();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            'preg_replace(): Argument #1 ($pattern) must be of type array when argument #2 ($replacement) is an array, string given'
        );
        $this->runPregReplace(
            $fn,
            $runtime,
            ['pattern' => '/a/', 'replacement' => ['A'], 'subject' => 'aba']
        );
    }

    /**
     * @param array{pattern: string|list<string>, replacement: string|list<string>, subject: string} $args
     */
    private function runPregReplace(Internal $fn, Runtime $runtime, array $args): string
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $pattern = new VMVariable();
        if (\is_string($args['pattern'])) {
            $pattern->string($args['pattern']);
        } else {
            $pattern->array($this->stringListToHashTable($args['pattern']));
        }
        $replacement = new VMVariable();
        if (\is_string($args['replacement'])) {
            $replacement->string($args['replacement']);
        } else {
            $replacement->array($this->stringListToHashTable($args['replacement']));
        }
        $subject = new VMVariable();
        $subject->string($args['subject']);
        $frame->calledArgs = [$pattern, $replacement, $subject];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->resolveIndirect()->toString();
    }

    /**
     * @param list<string> $values
     */
    private function stringListToHashTable(array $values): \PHPCompiler\VM\HashTable
    {
        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($values as $value) {
            $var = new VMVariable();
            $var->string($value);
            $ht->append($var);
        }

        return $ht;
    }
}
