<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getConstructor() — VM (#6302, ext/reflection/php_reflection.c). */
final class ReflectionClassGetConstructor extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getConstructor');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionClass_getConstructor — ZEND_PARSE_PARAMETERS (0 args) (#31033)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::getConstructor', 0);
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        // php-src ce->constructor walks inheritance including parent-private (__construct; #26059).
        $declaring = null;
        foreach (VmReflection::classHierarchyChain($entry, $ctx) as $class) {
            if (isset($class->methods['__construct'])) {
                $declaring = $class;
                break;
            }
        }
        if (null === $declaring) {
            $frame->returnVar->null();

            return;
        }
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object(ReflectionSupport::newReflectionMethodObject($ctx, $declaring, '__construct'));
        $frame->returnVar->copyFrom($out);
    }
}
