<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
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
        if (!isset($frame->calledArgs[0], $frame->calledArgs[1])) {
            throw new \LogicException('fputcsv() requires at least stream and fields arguments in this compiler build');
        }
        foreach (\array_keys($frame->calledArgs) as $idx) {
            if ($idx < 0 || $idx > 4) {
                throw new \ArgumentCountError(\sprintf(
                    'fputcsv() expects at most 5 arguments, %d given',
                    $idx + 1
                ));
            }
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $fieldsVar = $frame->calledArgs[1]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fputcsv');
        if (Variable::TYPE_ARRAY !== $fieldsVar->type) {
            throw new \LogicException('fputcsv() fields must be an array in this compiler build');
        }
        $separator = ',';
        $enclosure = '"';
        $escape = '\\';
        if (isset($frame->calledArgs[2])) {
            $separator = VmReflection::stringArg($frame->calledArgs[2], 'fputcsv() separator', 2);
        }
        if (isset($frame->calledArgs[3])) {
            $enclosure = VmReflection::stringArg($frame->calledArgs[3], 'fputcsv() enclosure', 3);
        }
        if (isset($frame->calledArgs[4])) {
            $escape = VmReflection::stringArg($frame->calledArgs[4], 'fputcsv() escape', 4);
        }
        VmCsvArg::validateFputcsvOptions($separator, $enclosure, $escape);
        $fields = [];
        foreach ($fieldsVar->toArray()->iterate(true) as $value) {
            $value = $value->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($value)) {
                $enumClass = EnumCaseSupport::enumClassForCaseVariable($value);
                throw new \Error(
                    'Object of class '.($enumClass->name ?? 'enum').' could not be converted to string'
                );
            }
            if (Variable::TYPE_STRING === $value->type) {
                $fields[] = $value->toString();
            } elseif (Variable::TYPE_INTEGER === $value->type) {
                $fields[] = (string) $value->toInt();
            } elseif (Variable::TYPE_OBJECT === $value->type) {
                throw new \Error(
                    'Object of class '.$value->toObject()->class->name.' could not be converted to string'
                );
            } else {
                throw new \LogicException(
                    'fputcsv() fields must be a list of strings or integers in this compiler build'
                );
            }
        }
        $written = VmFs::fputcsv(
            $handle,
            $fields,
            $separator,
            $enclosure,
            $escape
        );
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($written): void {
            if (false === $written) {
                $ret->bool(false);

                return;
            }
            $ret->int($written);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('fputcsv() requires at least stream and fields arguments in this compiler build');
        }
        if (\count($args) > 5) {
            throw new \LogicException('fputcsv() expects at most 5 arguments');
        }
        $compileTimeFailure = $this->emitCompileTimeCsvValidationFailure($context, ...$args);
        if (null !== $compileTimeFailure) {
            return $compileTimeFailure;
        }
        JitCsvArg::validateFputcsvCall($context, ...$args);
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
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $separator = JitStringArg::lower($context, $args[2], 'fputcsv() separator');
        }
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            $enclosure = JitStringArg::lower($context, $args[3], 'fputcsv() enclosure');
        }
        if (isset($args[4]) && !NamedOptionalCallArgs::isOmittedOptional($args[4])) {
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

    private function emitCompileTimeCsvValidationFailure(Context $context, JITVariable ...$args): ?Value
    {
        $checks = [
            [2, 'separator', false, 3],
            [3, 'enclosure', false, 4],
            [4, 'escape', true, 5],
        ];
        foreach ($checks as [$argIndex, $paramName, $allowEmpty, $argNum]) {
            if (!isset($args[$argIndex]) || NamedOptionalCallArgs::isOmittedOptional($args[$argIndex])) {
                continue;
            }
            $literal = $args[$argIndex]->compileTimeString ?? null;
            if (null === $literal) {
                continue;
            }
            $invalid = $allowEmpty ? \strlen($literal) > 1 : 1 !== \strlen($literal);
            if (!$invalid) {
                continue;
            }
            $message = $allowEmpty
                ? \sprintf('fputcsv(): Argument #%d ($%s) must be empty or a single character', $argNum, $paramName)
                : \sprintf('fputcsv(): Argument #%d ($%s) must be a single character', $argNum, $paramName);
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            $errBlock = BasicBlockHelper::append($context, 'fputcsv_csv_lit_err_'.$argNum);
            $afterBlock = BasicBlockHelper::append($context, 'fputcsv_csv_lit_after_'.$argNum);
            $context->builder->branch($errBlock);
            $context->builder->positionAtEnd($errBlock);
            TypeErrorRaise::emitValueError($context, $message);
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->positionAtEnd($afterBlock);

            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return null;
    }
}
