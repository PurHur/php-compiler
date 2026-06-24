<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringVarExportJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPCompiler\JIT\ValueEchoHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * var_export() subset for bootstrap/AOT (issue #4474 repro, #1492).
 */
final class var_export extends Internal
{
    private const CIRCULAR_WARNING = 'var_export does not handle circular references';

    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('var_export() requires one or two arguments in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        TypedPropertyCheck::assertReadable($v);
        $return = false;
        if (2 === $argc) {
            $retArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $retArg->type) {
                throw new \LogicException('var_export() return argument must be boolean in this compiler build');
            }
            $return = $retArg->toBool();
        }
        $exported = self::exportVm($v, $frame);
        if ($return) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->string($exported);
        } else {
            echo $exported;
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('var_export() requires one or two arguments in this compiler build (JIT/AOT)');
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            TypedPropertyUninitGuard::emitBeforeRead($context, $args[0]);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $args[0]->type) {
            self::echoBoolJit($context, self::boolValForBranch($context, $args[0]));
            $outSlot = JitValueBox::alloc($context);
            $outPtr = JitValueBox::pointer($context, $outSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $outPtr);

            return $outPtr;
        }
        StringVarExportJit::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $args[0]);
        $str = $context->builder->call(
            $context->lookupFunction('__compiler_var_export'),
            $valuePtr
        );
        $outSlot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $outSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
        if (1 === $argc) {
            ValueEchoHelper::echo($context, $outPtr);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $outPtr);
            $nullSlot = JitValueBox::alloc($context);
            $nullPtr = JitValueBox::pointer($context, $nullSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

            return $nullPtr;
        }
        $returns = self::boolValForBranch($context, $args[1]);
        $returnBb = BasicBlockHelper::append($context, 'var_export_return_mode');
        $echoBb = BasicBlockHelper::append($context, 'var_export_echo_mode');
        $doneBb = BasicBlockHelper::append($context, 'var_export_call_done');
        $context->builder->branchIf($returns, $returnBb, $echoBb);
        $context->builder->positionAtEnd($returnBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($echoBb);
        ValueEchoHelper::echo($context, $outPtr);
        $echoEndBb = $context->builder->getInsertBlock();
        $context->builder->call($context->lookupFunction('__value__writeNull'), $outPtr);
        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($outPtr, $returnBb);
        $result->addIncoming($nullPtr, $echoEndBb);

        return $result;
    }

    private static function exportVm(Variable $v, Frame $frame): string
    {
        /** @var \SplObjectStorage<int, true> $visited */
        $visited = new \SplObjectStorage();
        $warned = false;

        return self::exportVmNested($v, 0, $frame, $visited, $warned);
    }

    /**
     * @param \SplObjectStorage<int, true> $visited
     */
    private static function exportVmNested(
        Variable $v,
        int $level,
        Frame $frame,
        \SplObjectStorage $visited,
        bool &$warned
    ): string {
        $v = $v->resolveIndirect();
        // php-src var.c: closed/invalid resources export as NULL (#5148, #4920).
        if (ResourceSupport::isVmResource($v) && !is_resource_::isResource($v)) {
            return 'NULL';
        }
        // php-src var.c: stream contexts are resources but var_export prints NULL (#10704).
        if (VmStreamContext::isRepresentation($v)) {
            return 'NULL';
        }
        if (Variable::TYPE_BOOLEAN === $v->type) {
            return $v->toBool() ? 'true' : 'false';
        }
        if (Variable::TYPE_UNDEFINED === $v->type) {
            TypedPropertyCheck::assertReadable($v);

            return 'NULL';
        }
        if (Variable::TYPE_NULL === $v->type) {
            return 'NULL';
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            return (string) $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return VmVarExportFloat::format($v->toFloat());
        }
        if (Variable::TYPE_STRING === $v->type) {
            return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $v->toString())."'";
        }
        if (Variable::TYPE_ARRAY === $v->type) {
            $ht = $v->toArray();
            if ($visited->contains($ht)) {
                self::warnCircular($frame, $warned);

                return 'NULL';
            }
            $visited->attach($ht);
            try {
                return self::exportVmArray($ht, $level, $frame, $visited, $warned);
            } finally {
                $visited->detach($ht);
            }
        }
        if (Variable::TYPE_ENUM_CASE === $v->type) {
            $case = $v->toEnumCase();

            return self::exportVmEnumCaseLiteral($case->enumClass->name, $case->caseName);
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            return self::exportVmObject($v, $level, $frame, $visited, $warned);
        }

        throw new \LogicException('var_export() does not support this value type in this compiler build');
    }

    /**
     * Zend var_export() for enum cases: {@code \EnumName::Case} (zend_enum.c / var.c).
     */
    private static function exportVmEnumCaseLiteral(string $enumClassName, string $caseName): string
    {
        return '\\'.ltrim($enumClassName, '\\').'::'.$caseName;
    }

    /**
     * @param \SplObjectStorage<int, true> $visited
     */
    private static function exportVmObject(
        Variable $v,
        int $level,
        Frame $frame,
        \SplObjectStorage $visited,
        bool &$warned
    ): string {
        $object = $v->resolveIndirect()->toObject();
        if ($visited->contains($object)) {
            self::warnCircular($frame, $warned);

            return 'NULL';
        }
        $visited->attach($object);
        try {
            if (EnumCaseSupport::isEnumCase($object)) {
                return self::exportVmEnumCaseLiteral($object->class->name, $object->enumCaseName ?? '');
            }
            $className = $object->class->name;
            $props = VmReflection::getVarExportObjectProperties($v, $frame);
            $exported = self::exportVmArray($props->toArray(), $level, $frame, $visited, $warned);
            if ('stdClass' === $className) {
                return '(object) '.$exported;
            }

            return $className.'::__set_state('.$exported.')';
        } finally {
            $visited->detach($object);
        }
    }

    /**
     * @param \SplObjectStorage<int, true> $visited
     */
    private static function exportVmArray(
        HashTable $ht,
        int $level,
        Frame $frame,
        \SplObjectStorage $visited,
        bool &$warned
    ): string {
        $indent = str_repeat('  ', $level);
        $inner = str_repeat('  ', $level + 1);
        $lines = ["array (\n"];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $k = Variable::TYPE_INTEGER === $key->type
                ? (string) $key->toInt()
                : "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $key->toString())."'";
            $lines[] = $inner.$k.' => '.self::exportVmNested($value->resolveIndirect(), $level + 1, $frame, $visited, $warned).",\n";
        }
        $lines[] = $indent.")";

        return implode('', $lines);
    }

    private static function warnCircular(Frame $frame, bool &$warned): void
    {
        if ($warned || null === $frame->vmContext) {
            return;
        }
        $warned = true;
        $frame->vmContext->errors->triggerError(
            self::CIRCULAR_WARNING,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function echoJit(Context $context, JITVariable $arg): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $printf = $context->lookupFunction('printf');
        if (JITVariable::TYPE_VALUE === $arg->type || JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            self::echoBoolJit($context, self::boolValForBranch($context, $arg));

            return;
        }
        if (JITVariable::TYPE_NULL === $arg->type) {
            $context->builder->call(
                $printf,
                $context->builder->pointerCast($context->constantFromString('NULL'), $charPtr)
            );

            return;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $typeByte = $context->builder->load(
                $context->builder->structGep(
                    JitValueBox::valuePtrFromVariable($context, $arg),
                    $context->structFieldMap['__value__']['type']
                )
            );
            $i8 = $context->getTypeFromString('int8');
            $isNull = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NULL, false)
            );
            $done = BasicBlockHelper::append($context, 'var_export_null_done');
            $emit = BasicBlockHelper::append($context, 'var_export_null_emit');
            $context->builder->branchIf($isNull, $emit, $done);
            $context->builder->positionAtEnd($emit);
            $context->builder->call(
                $printf,
                $context->builder->pointerCast($context->constantFromString('NULL'), $charPtr)
            );
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);

            return;
        }

        throw new \LogicException('var_export() does not support this value type in this compiler build');
    }

    private static function boolValForBranch(Context $context, JITVariable $arg): Value
    {
        $boolVal = JITVariable::TYPE_VALUE === $arg->type
            ? $context->castToBool(JitValueBox::valuePtrFromVariable($context, $arg))
            : $context->helper->loadValue($arg);
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return $boolVal;
        }
        $i1 = $context->getTypeFromString('int1');
        $slot = $context->builder->alloca($i1, 1, 'var_export_bool_tmp');
        $context->builder->store($boolVal, $slot);

        return $context->builder->load($slot);
    }

    private static function echoBoolJit(Context $context, Value $boolVal): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $printf = $context->lookupFunction('printf');
        $trueBlock = BasicBlockHelper::append($context, 'var_export_bool_true');
        $falseBlock = BasicBlockHelper::append($context, 'var_export_bool_false');
        $doneBlock = BasicBlockHelper::append($context, 'var_export_bool_done');
        $context->builder->branchIf($boolVal, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        $context->builder->call(
            $printf,
            $context->builder->pointerCast($context->constantFromString('true'), $charPtr)
        );
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->call(
            $printf,
            $context->builder->pointerCast($context->constantFromString('false'), $charPtr)
        );
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
    }

    private static function exportJit(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_VALUE === $arg->type || JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            return self::exportBoolJit($context, self::boolValForBranch($context, $arg));
        }
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->builder->load($context->constantStringFromString('NULL'));
        }

        throw new \LogicException('var_export() does not support this value type in this compiler build');
    }

    private static function exportBoolJit(Context $context, Value $boolVal): Value
    {
        $trueStr = $context->constantStringFromString('true');
        $falseStr = $context->constantStringFromString('false');

        return $context->builder->select(
            $boolVal,
            $context->builder->load($trueStr),
            $context->builder->load($falseStr)
        );
    }
}
