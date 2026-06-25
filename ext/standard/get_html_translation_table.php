<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_html_translation_table() — HTML entity map (ext/standard/html.c, #3637).
 */
final class get_html_translation_table extends Internal
{
    public function __construct()
    {
        parent::__construct('get_html_translation_table');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 3) {
            throw new \LogicException(
                'get_html_translation_table() accepts zero to three arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $table = HTML_SPECIALCHARS;
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        $encoding = 'UTF-8';
        if ($argc >= 1) {
            $tableVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $tableVar->type) {
                throw new \LogicException(
                    'get_html_translation_table() table must be an integer in this compiler build'
                );
            }
            $table = $tableVar->toInt();
        }
        if ($argc >= 2) {
            $flagsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException(
                    'get_html_translation_table() flags must be an integer in this compiler build'
                );
            }
            $flags = $flagsVar->toInt();
        }
        if (3 === $argc) {
            $encVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $encVar->type) {
                throw new \LogicException(
                    'get_html_translation_table() encoding must be a string in this compiler build'
                );
            }
            $encoding = $encVar->toString();
        }
        $frame->returnVar->array(VmString::getHtmlTranslationTable($table, $flags, $encoding));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 3) {
            throw new \LogicException(
                'get_html_translation_table() accepts zero to three arguments in this compiler build'
            );
        }

        return JitGetHtmlTranslationTable::invoke($context, ...$args);
    }
}
