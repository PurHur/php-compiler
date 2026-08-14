<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * preg_replace_callback_array() — VM any callable; JIT/AOT via PregReplaceCallbackArrayRuntime (#3568).
 *
 * php-src: ext/pcre/php_pcre.c — PHP_FUNCTION(preg_replace_callback_array)
 */
final class preg_replace_callback_array extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_replace_callback_array');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/pcre/php_pcre.stub.php — ArgumentCountError (#30966).
        $this->requireArgCountRange($frame, 'preg_replace_callback_array', 2, 5);
        $argc = \count($frame->calledArgs);
        if (null === $frame->vmContext) {
            throw new \LogicException(
                'preg_replace_callback_array() requires VM context in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $patternsArg = $frame->calledArgs[0]->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($patternsArg)) {
            throw new \TypeError(\sprintf(
                'preg_replace_callback_array(): Argument #1 ($pattern) must be of type array, %s given',
                EnumCaseSupport::typeNameForVariable($patternsArg)
            ));
        }
        if (Variable::TYPE_ARRAY !== $patternsArg->type) {
            throw new \TypeError(\sprintf(
                'preg_replace_callback_array(): Argument #1 ($pattern) must be of type array, %s given',
                self::typeLabel($patternsArg)
            ));
        }

        // $subject soft-null: E_DEPRECATED + '' on 8.4 (php-src php_pcre.c / #21318, re-#21198).
        $subjectVar = VmPreg::resolveStringOrArraySubject(
            $frame,
            $frame->calledArgs[1],
            'preg_replace_callback_array',
            1,
            'subject'
        );

        // Named args may skip limit (count: $n) — use isset, not argc (#19697).
        $limit = -1;
        if (isset($frame->calledArgs[2])) {
            $limitVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitVar->type) {
                throw new \TypeError(
                    'preg_replace_callback_array(): Argument #3 ($limit) must be of type int, '
                    .self::typeLabel($limitVar).' given'
                );
            }
            $limit = $limitVar->toInt();
        }

        $hasCount = isset($frame->calledArgs[3]);
        $flags = 0;
        if (isset($frame->calledArgs[4])) {
            $flagsVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \TypeError(
                    'preg_replace_callback_array(): Argument #5 ($flags) must be of type int, '
                    .self::typeLabel($flagsVar).' given'
                );
            }
            $flags = $flagsVar->toInt();
        }

        $totalCount = 0;
        $result = VmPregReplaceCallbackArray::invoke(
            $frame->vmContext,
            $patternsArg->toArray(),
            $subjectVar,
            $limit,
            $totalCount,
            $flags,
            $frame
        );
        if ($hasCount) {
            $frame->calledArgs[3]->resolveIndirect()->int($totalCount);
        }
        self::assignReturn($frame, $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'preg_replace_callback_array', 2, 5)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        JitPregSubject::requireStringOrArray($context, $args[1], 'preg_replace_callback_array', 1, 'subject');
        if (!JitPregSubject::isStringOrCoercibleNullSubject($args[1])) {
            throw new \LogicException(
                'preg_replace_callback_array() array subject is not supported for JIT/AOT in this compiler build'
            );
        }

        return JitPregReplaceCallbackArray::invoke($context, $args[0], $args[1]);
    }

    /**
     * @param string|false|HashTable $result
     */
    private static function assignReturn(Frame $frame, string|false|HashTable $result): void
    {
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_string($result)) {
            $frame->returnVar->string($result);

            return;
        }
        $frame->returnVar->array($result);
    }

    private static function typeLabel(Variable $var): string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::typeNameForVariable($var);
        }

        return match ($var->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
