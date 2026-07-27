<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionParameter::__construct($function, $parameter) — VM (#7072, ext/reflection/php_reflection.c). */
final class ReflectionParameterConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 2) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionParameter', 2, $argc);
        }
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $functionArg = $frame->calledArgs[1]->resolveIndirect();
        $parameterArg = $frame->calledArgs[2]->resolveIndirect();

        if (Variable::TYPE_ARRAY === $functionArg->type) {
            $this->initForMethod($ctx, $receiver, $functionArg, $parameterArg);

            return;
        }

        $functionName = VmReflection::normalizeGlobalIntrospectionName(
            VmReflection::stringArg(
                $frame->calledArgs[1],
                'ReflectionParameter::__construct() function',
                1
            )
        );
        $func = ReflectionSupport::resolveFunctionForReflection($ctx, $functionName);
        if ($func instanceof Internal) {
            $paramNames = BuiltinParamNames::paramNamesForInternalFunction($functionName);
            if (null === $paramNames) {
                ReflectionSupport::throwReflectionException(
                    ReflectionSupport::functionNotFoundMessage($functionName)
                );
            }
            $this->initForInternalFunction($receiver, $functionName, $parameterArg, $paramNames);

            return;
        }
        if (!$func instanceof PhpFunc) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::functionNotFoundMessage($functionName)
            );
        }
        $index = $this->resolveParameterIndex($parameterArg, $func->block->paramNames, 'function');
        if (!isset($func->block->paramNames[$index])) {
            ReflectionSupport::throwReflectionException(
                'Parameter '.$index.' does not exist on function '.$functionName.'()'
            );
        }
        $receiver->getProperty(ReflectionSupport::PROP_FUNC_NAME)->string($functionName);
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_CLASS)->null();
        $receiver->getProperty(ReflectionSupport::PROP_METHOD_NAME)->null();
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_INDEX)->int($index);
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_POSITION)->int($index);
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_NAME)->string($func->block->paramNames[$index]);
        $receiver->constructed = true;
    }

    private function initForMethod(
        \PHPCompiler\VM\Context $ctx,
        ObjectEntry $receiver,
        Variable $functionArg,
        Variable $parameterArg,
    ): void {
        $ht = $functionArg->toArray();
        if (2 !== $ht->getNumElements()) {
            throw new \LogicException('ReflectionParameter::__construct() class/method array must have two elements');
        }
        $className = $ht->findIndex(0)->resolveIndirect();
        $methodName = $ht->findIndex(1)->resolveIndirect();
        if (Variable::TYPE_STRING !== $className->type || Variable::TYPE_STRING !== $methodName->type) {
            throw new \LogicException('ReflectionParameter::__construct() class and method must be strings');
        }
        $class = $className->toString();
        $method = $methodName->toString();
        $entry = VmReflection::resolveClassEntry($ctx, $class);
        if (null === $entry) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::classNotFoundMessage($class)
            );
        }
        $methodLc = strtolower($method);
        if (!isset($entry->methods[$methodLc]) && !isset($entry->abstractMethods[$methodLc])) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::methodNotFoundMessage($entry->name, $method)
            );
        }
        $paramNames = ReflectionSupport::methodParameterNames($entry, $method);
        $position = $this->resolveParameterIndex($parameterArg, $paramNames, 'method');
        if (!isset($paramNames[$position])) {
            ReflectionSupport::throwReflectionException(
                'Parameter '.$position.' does not exist on method '.$entry->name.'::'.$method.'()'
            );
        }
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_CLASS)->string($entry->name);
        $receiver->getProperty(ReflectionSupport::PROP_METHOD_NAME)->string($method);
        $receiver->getProperty(ReflectionSupport::PROP_FUNC_NAME)->null();
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_POSITION)->int($position);
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_INDEX)->int($position);
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_NAME)->string($paramNames[$position]);
        $receiver->constructed = true;
    }

    /**
     * @param list<string> $paramNames
     */
    private function initForInternalFunction(
        ObjectEntry $receiver,
        string $functionName,
        Variable $parameterArg,
        array $paramNames,
    ): void {
        $index = $this->resolveParameterIndex($parameterArg, $paramNames, 'function');
        if (!isset($paramNames[$index])) {
            ReflectionSupport::throwReflectionException(
                'Parameter '.$index.' does not exist on function '.$functionName.'()'
            );
        }
        $receiver->getProperty(ReflectionSupport::PROP_FUNC_NAME)->string($functionName);
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_CLASS)->null();
        $receiver->getProperty(ReflectionSupport::PROP_METHOD_NAME)->null();
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_INDEX)->int($index);
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_POSITION)->int($index);
        $displayName = ltrim((string) $paramNames[$index], '&');
        if (str_starts_with($displayName, '...')) {
            $displayName = substr($displayName, 3);
        }
        $displayName = rtrim($displayName, '=');
        $receiver->getProperty(ReflectionSupport::PROP_PARAM_NAME)->string($displayName);
        $receiver->constructed = true;
    }

    /**
     * @param list<string> $paramNames
     */
    private function resolveParameterIndex(Variable $parameterArg, array $paramNames, string $context): int
    {
        if (Variable::TYPE_INTEGER === $parameterArg->type) {
            return $parameterArg->toInt();
        }
        if (Variable::TYPE_STRING === $parameterArg->type) {
            $name = $parameterArg->toString();
            $index = array_search($name, $paramNames, true);
            if (false === $index) {
                // BuiltinParamNames may keep trailing `=` optionality markers (#23608).
                foreach ($paramNames as $i => $label) {
                    $bare = rtrim(ltrim((string) $label, '&'), '=');
                    if (str_starts_with($bare, '...')) {
                        $bare = substr($bare, 3);
                    }
                    if ($bare === $name) {
                        $index = $i;
                        break;
                    }
                }
            }
            if (false === $index) {
                ReflectionSupport::throwReflectionException(
                    'Parameter '.$name.' does not exist on '.$context
                );
            }

            return (int) $index;
        }

        throw new \TypeError(
            'ReflectionParameter::__construct(): Argument #2 ($parameter) must be of type string|int, '
            .ReflectionSupport::valueTypeLabelPublic($parameterArg).' given'
        );
    }
}
