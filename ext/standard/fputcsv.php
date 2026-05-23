<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fputcsv() — VM via VmFs; JIT/AOT via implode + __compiler_fwrite (issue #1193). */
final class fputcsv extends Internal
{
    public function __construct()
    {
        parent::__construct('fputcsv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('fputcsv() requires two to five arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $fieldsVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('fputcsv() handle must be an integer in this compiler build');
        }
        if (Variable::TYPE_ARRAY !== $fieldsVar->type) {
            throw new \LogicException('fputcsv() fields must be an array in this compiler build');
        }
        $separator = ',';
        $enclosure = '"';
        $escape = '\\';
        if ($argc >= 3) {
            $separator = VmReflection::stringArg($frame->calledArgs[2], 'fputcsv() separator');
        }
        if ($argc >= 4) {
            $enclosure = VmReflection::stringArg($frame->calledArgs[3], 'fputcsv() enclosure');
        }
        if ($argc >= 5) {
            $escape = VmReflection::stringArg($frame->calledArgs[4], 'fputcsv() escape');
        }
        $fields = [];
        foreach ($fieldsVar->toArray()->iterate(true) as $value) {
            $value = $value->resolveIndirect();
            if (Variable::TYPE_STRING === $value->type) {
                $fields[] = $value->toString();
            } elseif (Variable::TYPE_INTEGER === $value->type) {
                $fields[] = (string) $value->toInt();
            } else {
                throw new \LogicException(
                    'fputcsv() fields must be a list of strings or integers in this compiler build'
                );
            }
        }
        $written = VmFs::fputcsv(
            $handleVar->toInt(),
            $fields,
            $separator,
            $enclosure,
            $escape
        );
        if (false === $written) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('fputcsv() requires two to five arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'fputcsv() handle'),
            $i64
        );
        $fields = $this->loadFields($context, $args[1]);
        $separator = $strPtr->constNull();
        $enclosure = $strPtr->constNull();
        $escape = $strPtr->constNull();
        if ($argc >= 3) {
            $separator = JitStringArg::lower($context, $args[2], 'fputcsv() separator');
        }
        if ($argc >= 4) {
            $enclosure = JitStringArg::lower($context, $args[3], 'fputcsv() enclosure');
        }
        if ($argc >= 5) {
            $escape = JitStringArg::lower($context, $args[4], 'fputcsv() escape');
        }

        return JitFputcsv::invoke($context, $handle, $fields, $separator, $enclosure, $escape);
    }

    private function loadFields(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::pointer($context, $arg->value)
            );
        }

        throw new \LogicException('fputcsv() fields must be an array in this compiler build');
    }
}
