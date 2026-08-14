<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionAttribute::newInstance() — VM (#3206, #3800). */
final class ReflectionAttributeNewInstance extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('newInstance');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args); $calledArgs[0] is $this (#30896)
        $this->requireExactUserArgCount($frame, 'ReflectionAttribute::newInstance', 0);
        $receiver = ReflectionSupport::requireReflectionAttribute($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $nameVar = $receiver->getProperty(ReflectionSupport::PROP_ATTR_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionAttribute missing name');
        }
        $className = $nameVar->toString();
        $lc = strtolower(ltrim($className, '\\'));
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lc])) {
            // Zend ext/reflection/php_reflection.c — Error when attribute class is missing (#3206, #3216).
            throw new \Error('Attribute class "'.$className.'" not found');
        }
        $classEntry = $ctx->classes[$lc];
        // Class must carry #[Attribute] (php-src ZEND_ACC_ATTRIBUTE, #24930).
        ReflectionSupport::assertAttributeNewInstanceIsAttributeClass($receiver, $classEntry);
        // Wrong Attribute::TARGET_* on declaration site → Error (php-src newInstance, #23528).
        ReflectionSupport::assertAttributeNewInstanceTargetAllowed($receiver, $classEntry);
        // Userland non-IS_REPEATABLE duplicates: Error at newInstance, not compile (#22930).
        ReflectionSupport::assertAttributeNewInstanceNotIllegalRepeat($receiver, $classEntry);
        // Delayed #[\DelayedTargetValidation] internal validator errors (#26241).
        ReflectionSupport::assertAttributeNewInstanceNoDelayedValidationError($receiver);
        // Abstract / interface / trait / enum: Error like `new` (php-src zend_get_attribute_object, #26238).
        ReflectionSupport::assertAttributeNewInstanceInstantiable($classEntry);
        $argSpecs = ReflectionSupport::argsFromReflectionObject($receiver);
        // Args + no ctor → Error (php-src object_init_with_constructor, #29955).
        ReflectionSupport::assertAttributeNewInstanceCtorAllowsArgs($classEntry, $argSpecs);
        $object = new ObjectEntry($classEntry);
        $thisVar = new Variable();
        $thisVar->object($object);
        $vm = VM::running();
        if (null === $vm) {
            throw new \LogicException('ReflectionAttribute::newInstance() requires active VM');
        }
        if (null !== $classEntry->constructor) {
            $invokeArgs = ReflectionSupport::constructorInvokeVariables(
                $classEntry->constructor,
                $argSpecs,
                $ctx
            );
            ReflectionSupport::invokeAttributeConstructor(
                $vm,
                $ctx,
                $classEntry->constructor,
                $thisVar,
                $invokeArgs
            );
            ReflectionSupport::applyConstructorPropertyArgs($object, $classEntry->constructor, $argSpecs, $ctx);
            $object->constructed = true;
        } else {
            $object->constructed = true;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($object);
        }
    }
}
