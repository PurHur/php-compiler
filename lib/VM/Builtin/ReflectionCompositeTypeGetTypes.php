<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionUnionType::getTypes() / ReflectionIntersectionType::getTypes() — VM (#3355, #11545). */
final class ReflectionCompositeTypeGetTypes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTypes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionType($frame, $frame->calledArgs[0]);
        $classLc = strtolower($receiver->class->name);
        if (ReflectionSupport::REFLECTION_UNION_TYPE !== $classLc
            && ReflectionSupport::REFLECTION_INTERSECTION_TYPE !== $classLc) {
            throw new \LogicException('Expected ReflectionUnionType or ReflectionIntersectionType instance');
        }
        // php-src: zim_ReflectionUnionType_getTypes / Intersection — ZEND_PARSE_PARAMETERS (0) (#30896)
        $this->requireExactUserArgCount($frame, $receiver->class->name.'::getTypes', 0);
        $members = $receiver->getProperty(ReflectionSupport::PROP_TYPE_MEMBERS)->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $members->type) {
            throw new \LogicException('Reflection composite type missing member types');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($members);
        }
    }
}
