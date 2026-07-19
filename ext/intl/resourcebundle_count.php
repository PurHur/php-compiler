<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * resourcebundle_count() — procedural alias of ResourceBundle::count()
 * (php-src resourcebundle_class.c / Countable; #20781).
 */
final class resourcebundle_count extends Internal
{
    public function __construct()
    {
        parent::__construct('resourcebundle_count');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'resourcebundle_count() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmResourceBundle::isResourceBundleObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'resourcebundle_count(): Argument #1 ($bundle) must be of type ResourceBundle, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmResourceBundle::count($receiver->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('resourcebundle_count() is not implemented for JIT in this compiler build (issue #20781)');
    }
}
