<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionNamedType::getName() — VM (#3355). */
final class ReflectionNamedTypeGetName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getName');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args); $calledArgs[0] is $this (#30896)
        $this->requireExactUserArgCount($frame, 'ReflectionNamedType::getName', 0);
        $receiver = ReflectionSupport::requireReflectionType($frame, $frame->calledArgs[0]);
        if (strtolower($receiver->class->name) !== ReflectionSupport::REFLECTION_NAMED_TYPE) {
            throw new \LogicException('Expected ReflectionNamedType instance');
        }
        $nameVar = $receiver->getProperty(ReflectionSupport::PROP_TYPE_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionNamedType missing type name');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($nameVar->toString());
        }
    }
}
