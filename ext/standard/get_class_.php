<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** get_class() — class name of an object (issue #1217, #5456, #17395). */
final class get_class_ extends Internal
{
    private const TYPE_ERROR = 'get_class(): Argument #1 ($object) must be of type object, %s given';

    public function __construct()
    {
        parent::__construct('get_class');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        VmReflection::enforceGetClassMaxArgs($argc);
        if (0 === $argc) {
            if (CompilerVersion::supportsGetClassParentClassParameterlessDeprecation()) {
                VmEngineBuiltinDeprecation::emitCallingWithoutArguments($frame, 'get_class');
            }
            $definingClass = VmReflection::zeroArgGetClassName($frame);
            BuiltinExecute::writeReturn(
                $frame,
                static function (Variable $ret) use ($definingClass): void {
                    $ret->string($definingClass);
                }
            );

            return;
        }
        $value = $frame->calledArgs[0]->resolveIndirect();
        BuiltinExecute::writeReturn(
            $frame,
            function (Variable $ret) use ($value): void {
                if (Variable::TYPE_STRING === $value->type) {
                    throw new \TypeError(\sprintf(self::TYPE_ERROR, 'string'));
                }
                if (Variable::TYPE_ENUM_CASE === $value->type) {
                    $ret->string($value->toEnumCase()->enumClass->name);

                    return;
                }
                if (ResourceSupport::rejectsGetClassOperand($value)) {
                    throw new \TypeError(\sprintf(self::TYPE_ERROR, 'resource'));
                }
                if (Variable::TYPE_OBJECT !== $value->type) {
                    // zend_zval_value_name — bool → true/false (#29097 / #29631).
                    throw new \TypeError(\sprintf(
                        self::TYPE_ERROR,
                        EnumCaseSupport::typeNameForTypeErrorActual($value)
                    ));
                }
                $ret->string($value->toObject()->class->name);
            }
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $maxArgs = VmReflection::getClassMaxArgCount();
        if ($argc > $maxArgs) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('get_class() expects at most %d argument%s, %d given', $maxArgs, 1 === $maxArgs ? '' : 's', $argc)
            );

            return $context->getTypeFromString('int32')->constInt(0, false);
        }
        if (0 === $argc) {
            return JitGetClass::invokeNoArg($context);
        }

        // php-src arity 1 — never pass allow_string (#28310); keep known-false for JitGetClass IR.
        return JitGetClass::invoke(
            $context,
            $args[0],
            $context->constantFromBool(false),
            true
        );
    }
}
