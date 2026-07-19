<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * resourcebundle_create() — procedural alias of ResourceBundle::create()
 * (php-src resourcebundle_class.c / resourcebundle.stub.php; #20814).
 *
 * Optional $fallback is accepted for php-src signature parity; v1 open path
 * matches ResourceBundle::create (ures_open / ICU default fallback).
 */
final class resourcebundle_create extends Internal
{
    public function __construct()
    {
        parent::__construct('resourcebundle_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'resourcebundle_create() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $locale = VmResourceBundle::coerceLocaleArg($frame->calledArgs[0], 'resourcebundle_create', 0);
        $bundle = VmResourceBundle::coerceBundleArg($frame->calledArgs[1], 'resourcebundle_create', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmResourceBundle::create($frame->vmContext, $locale, $bundle);
        if (null === $object) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($object);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('resourcebundle_create() is not implemented for JIT in this compiler build (issue #20814)');
    }
}
