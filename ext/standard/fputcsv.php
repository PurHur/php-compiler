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
        // php-src ext/standard/file.c — ArgumentCountError (#25407).
        $this->requireArgCountRange($frame, 'fputcsv', 2, 6);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $fieldsVar = $frame->calledArgs[1]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fputcsv');
        $fieldsHt = VmArray::requireArrayParam($fieldsVar, 'fputcsv', 2, 'fields');
        $separator = ',';
        $enclosure = '"';
        $escape = '\\';
        $eol = "\n";
        if (isset($frame->calledArgs[2])) {
            $separator = VmReflection::stringArg($frame->calledArgs[2], 'fputcsv() separator', 2);
        }
        if (isset($frame->calledArgs[3])) {
            $enclosure = VmReflection::stringArg($frame->calledArgs[3], 'fputcsv() enclosure', 3);
        }
        $escapeOmitted = !isset($frame->calledArgs[4]);
        if (!$escapeOmitted) {
            $escape = VmReflection::stringArg($frame->calledArgs[4], 'fputcsv() escape', 4);
        }
        if (isset($frame->calledArgs[5])) {
            $eol = VmReflection::stringArg($frame->calledArgs[5], 'fputcsv() eol', 5);
        }
        // php-src: validate separator/enclosure before omitted-$escape DEP (#29383, file.c).
        VmCsvArg::validateFputcsvOptions($separator, $enclosure, $escape);
        if ($escapeOmitted) {
            // php-src 8.4+: omitted $escape → E_DEPRECATED (#21179, file.c).
            VmCsvArg::emitOmittedEscapeDeprecation($frame, 'fputcsv');
        }
        $fields = VmFputcsv::coerceFieldList($fieldsHt->iterate(true));
        $written = VmFs::fputcsv(
            $handle,
            $fields,
            $separator,
            $enclosure,
            $escape,
            $eol
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
        if (!$this->requireArgCountRangeJit($context, $args, 'fputcsv', 2, 6)) {
            return $context->constantFromInteger(0, 'int64');
        }
        $compileTimeFailure = $this->emitCompileTimeCsvValidationFailure($context, ...$args);
        if (null !== $compileTimeFailure) {
            return $compileTimeFailure;
        }
        $compileTimeFailure = $this->emitCompileTimeNullFieldsFailure($context, $args[1]);
        if (null !== $compileTimeFailure) {
            return $compileTimeFailure;
        }
        if (!JitCsvArg::validateFputcsvCall($context, ...$args)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        JitArrayElem::requireArrayParam($context, $args[1], 'fputcsv', 2, 'fields');
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
        $eol = $strPtr->constNull();
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $separator = JitStringArg::lower($context, $args[2], 'fputcsv() separator');
        }
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            $enclosure = JitStringArg::lower($context, $args[3], 'fputcsv() enclosure');
        }
        $escapeOmitted = !isset($args[4]) || NamedOptionalCallArgs::isOmittedOptional($args[4]);
        if (!$escapeOmitted) {
            $escape = JitStringArg::lower($context, $args[4], 'fputcsv() escape');
        }
        // Deprecation after compile-time/runtime CSV validation (#29383) — validate* already ran above.
        if ($escapeOmitted) {
            // php-src 8.4+: omitted $escape → E_DEPRECATED (#21179, file.c).
            VmCsvArg::emitJitOmittedEscapeDeprecation($context, 'fputcsv');
        }
        if (isset($args[5]) && !NamedOptionalCallArgs::isOmittedOptional($args[5])) {
            $eol = JitStringArg::lower($context, $args[5], 'fputcsv() eol');
        }

        return JitFputcsv::invoke($context, $handle, $fields, $separator, $enclosure, $escape, $eol);
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

    private function emitCompileTimeNullFieldsFailure(Context $context, JITVariable $fieldsArg): ?Value
    {
        if (JITVariable::TYPE_NULL !== $fieldsArg->type && !($fieldsArg->isNullConstant ?? false)) {
            return null;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $errBlock = BasicBlockHelper::append($context, 'fputcsv_null_fields_err');
        $afterBlock = BasicBlockHelper::append($context, 'fputcsv_null_fields_after');
        $context->builder->branch($errBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::emitRaise(
            $context,
            'fputcsv(): Argument #2 ($fields) must be of type array, null given'
        );
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($afterBlock);

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
