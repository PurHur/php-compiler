<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * iconv_mime_encode() — encode RFC 2047 header fields (php-src ext/iconv/iconv.c; #6364).
 *
 * php-src stub: array $options = [] (not nullable) — explicit null → TypeError (#31310).
 */
final class iconv_mime_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('iconv_mime_encode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'iconv_mime_encode() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $fieldName = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'iconv_mime_encode',
            0,
            'field_name'
        );
        $fieldValue = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'iconv_mime_encode',
            1,
            'field_value'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $preferences = null;
        if ($argc >= 3) {
            $arg = $frame->calledArgs[2]->resolveIndirect();
            // php-src Z_PARAM_ARRAY — null is not “omit”; TypeError (#31310).
            if (Variable::TYPE_ARRAY !== $arg->type) {
                throw new \TypeError(\sprintf(
                    'iconv_mime_encode(): Argument #3 ($options) must be of type array, %s given',
                    self::typeLabel($arg)
                ));
            }
            $preferences = [];
            foreach ($arg->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                if (Variable::TYPE_STRING !== $keyVar->type) {
                    continue;
                }
                $key = $keyVar->toString();
                $valueVar = $valueVar->resolveIndirect();
                if (Variable::TYPE_STRING === $valueVar->type) {
                    $preferences[$key] = $valueVar->toString();
                } elseif (Variable::TYPE_INTEGER === $valueVar->type) {
                    $preferences[$key] = $valueVar->toInt();
                }
            }
        }
        $result = VmIconvMime::mimeEncode($fieldName, $fieldValue, $preferences, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitIconvMime::invokeEncode($context, ...$args);
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
