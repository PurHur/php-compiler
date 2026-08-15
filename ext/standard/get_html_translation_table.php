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
 *
 * Excess argc → Zend ArgumentCountError (#30720; php-src ext/standard/html.c).
 */
final class get_html_translation_table extends Internal
{
    public function __construct()
    {
        parent::__construct('get_html_translation_table');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 0..3 (#30720; ext/standard/html.stub.php).
        $this->requireAtMostArgCount($frame, 'get_html_translation_table', 3);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $table = HTML_SPECIALCHARS;
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        $encoding = 'UTF-8';
        // userArgIndex is 1-based for DEP/TypeError (VmNullNumberParamDeprecation); slot 0 cited #0 (#29395).
        if ($argc >= 1) {
            $table = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                0,
                'get_html_translation_table',
                1,
                'table'
            );
        }
        if ($argc >= 2) {
            $flags = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                1,
                'get_html_translation_table',
                2,
                'flags'
            );
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
        // Catchable ArgumentCountError under AOT try/catch (#30720).
        if (!$this->requireAtMostJitArgCount($context, $args, 'get_html_translation_table', 3)) {
            return $context->getTypeFromString('__hashtable__*')->constNull();
        }

        return JitGetHtmlTranslationTable::invoke($context, ...$args);
    }
}
