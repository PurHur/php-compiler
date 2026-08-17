<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\ext\standard\VmEval;
use PHPLLVM\Value;

/**
 * Exception / Error hierarchy __construct — store message (+ code defaults) (#23641).
 *
 * php-src: Zend/zend_exceptions.c — zend_default_exception_new_ex
 * VM SSOT: {@see \PHPCompiler\VM\Builtin\ExceptionConstruct}
 *
 * Returns a null value box like other void JIT ctors ({@see ReflectionClassConstruct}) so
 * FUNCCALL_EXEC_RETURN does not replace the `new` object with a bare `__object__*` in a way
 * that loses Throwable typing for subsequent `throw` (#23641).
 *
 * `$previous` (?Throwable) is validated and stored (#28798).
 */
final class ExceptionConstruct implements Call
{
    /**
     * @param string $constructClassName Class used in TypeError wire text (Exception/Error/…)
     * @param int    $previousArgIndex   Index in $args including $this (3 for Exception/Error, 6 for ErrorException)
     */
    public function __construct(
        private readonly string $constructClassName = 'Exception',
        private readonly int $previousArgIndex = 3,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Exception::__construct() requires $this');
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $object = $context->type->object;

        if (isset($args[1]) && Variable::TYPE_NULL !== $args[1]->type && empty($args[1]->isNullConstant)) {
            // FuncCall temps are often TYPE_VALUE boxes — the old non-STRING → "" path wiped
            // UnhandledMatchError messages from phpc_match_unhandled_operand_message (#29747).
            // Zend: string $message — Z_PARAM_STR coerce via JitStringBuiltinArg (incl. boxed).
            $msgStr = JitStringBuiltinArg::lower(
                $context,
                $args[1],
                $this->constructClassName.'::__construct',
                0,
                'message'
            );
            $msgVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $msgStr);
        } else {
            $msgStr = $context->builder->load($context->constantStringFromString(''));
            $msgVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $msgStr);
        }

        // Store on the receiver's runtime class when known; else Exception/Error layout.
        $decl = 'Exception';
        $receiver = $args[0];
        if (Variable::TYPE_OBJECT === $receiver->type) {
            // Prefer Exception/Error declaring class (getMessage reads Exception::message).
            foreach (['Exception', 'Error'] as $candidate) {
                try {
                    $cid = $object->lookup($candidate);
                } catch (\Throwable) {
                    continue;
                }
                if ($object->hasProperty($cid, ExceptionSupport::PROP_MESSAGE)) {
                    $decl = $candidate;
                    break;
                }
            }
        }
        $object->storeInstanceProperty($obj, $decl, ExceptionSupport::PROP_MESSAGE, $msgVar);

        // Zend zend_exceptions.c — file/line from construct call site (#23641).
        $filePath = $context->jitAotEntryScriptPath;
        if ('' === $filePath) {
            $filePath = 'Unknown';
        }
        $fileStr = $context->builder->load($context->constantStringFromString($filePath));
        $fileVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $fileStr);
        $object->storeInstanceProperty($obj, $decl, ExceptionSupport::PROP_FILE, $fileVar);
        $line = max(0, $context->callSiteLine);
        // wrapEvalCode prepends `<?php\n` — Zend Exception::getLine() is 1-based in the eval string (#31948).
        if ($context->evalInlineDepth > 0 && $line > 0) {
            $line = VmEval::unwrapEvalLine($line);
        }
        $lineVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->constantFromInteger($line)
        );
        $object->storeInstanceProperty($obj, $decl, ExceptionSupport::PROP_LINE, $lineVar);

        if (isset($args[2])) {
            if (Variable::TYPE_NULL === $args[2]->type) {
                // Zend 8.1+ typed int $code — null→0 with E_DEPRECATED (#28797).
                JitStringBuiltinArg::emitNullStringParamDeprecation(
                    $context,
                    $this->constructClassName.'::__construct',
                    1,
                    'code',
                    'int'
                );
                $codeVar = new Variable(
                    $context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $context->constantFromInteger(0)
                );
                $object->storeInstanceProperty($obj, $decl, ExceptionSupport::PROP_CODE, $codeVar);
            } elseif (
                Variable::TYPE_NATIVE_LONG === $args[2]->type
                || Variable::TYPE_NATIVE_DOUBLE === $args[2]->type
                || Variable::TYPE_NATIVE_BOOL === $args[2]->type
                || Variable::TYPE_STRING === $args[2]->type
                || Variable::TYPE_VALUE === $args[2]->type
            ) {
                // Zend Z_PARAM_LONG — coerce float/bool/numeric string / value boxes (#28797).
                $longVal = JitLongArg::lower(
                    $context,
                    $args[2],
                    $this->constructClassName.'::__construct code'
                );
                $codeVar = new Variable(
                    $context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $longVal
                );
                $object->storeInstanceProperty($obj, $decl, ExceptionSupport::PROP_CODE, $codeVar);
            }
        }

        // Zend ?Throwable $previous — TypeError on wrong type (#28798).
        // Argument #N matches $previousArgIndex ($this is args[0], formals start at 1).
        if (
            isset($args[$this->previousArgIndex])
            && Variable::TYPE_NULL !== $args[$this->previousArgIndex]->type
        ) {
            $this->storePreviousOrTypeError(
                $context,
                $object,
                $obj,
                $decl,
                $args[$this->previousArgIndex]
            );
        }

        $object->markObjectConstructed($obj);

        // Void ctor result — do not overwrite `new` temp (VM #4540 / ReflectionClassConstruct).
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private function storePreviousOrTypeError(
        Context $context,
        mixed $object,
        Value $obj,
        string $decl,
        Variable $previous
    ): void {
        $prefix = sprintf(
            '%s::__construct(): Argument #%d ($previous) must be of type ?Throwable, ',
            $this->constructClassName,
            $this->previousArgIndex
        );

        if (
            Variable::TYPE_OBJECT !== $previous->type
            && Variable::TYPE_VALUE !== $previous->type
        ) {
            TryCatchHelper::emitCatchableClassError(
                $context,
                'TypeError',
                $prefix.$this->jitTypeLabel($previous).' given'
            );

            return;
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'exception_prev_check');
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $insert) {
            TryCatchHelper::emitCatchableClassError(
                $context,
                'TypeError',
                $prefix.'object given'
            );

            return;
        }
        $func = $insert->getParent();
        $okBb = $func->appendBasicBlock('exception_prev_ok');
        $badBb = $func->appendBasicBlock('exception_prev_bad');
        $isThrowable = ReflectionBuiltinHelper::emitInstanceOf($context, $previous, 'Throwable');
        $isBool = Variable::TYPE_NATIVE_BOOL === $isThrowable->type
            ? $isThrowable->value
            : $context->helper->loadValue($isThrowable);
        $context->builder->branchIf($isBool, $okBb, $badBb);

        $context->builder->positionAtEnd($badBb);
        // Zend wire text uses the runtime class name; compile-time path uses "object"
        // when the concrete class is not known here (NestedJIT/VM covers literal stdClass).
        TryCatchHelper::emitCatchableClassError(
            $context,
            'TypeError',
            $prefix.'object given'
        );

        $context->builder->positionAtEnd($okBb);
        $object->storeInstanceProperty($obj, $decl, ExceptionSupport::PROP_PREVIOUS, $previous);
    }

    private function jitTypeLabel(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_HASHTABLE => 'array',
            default => 'mixed',
        };
    }
}
