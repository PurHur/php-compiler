<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * exif_tagname() — EXIF IFD tag index to name (ext/exif/exif.c, #6105).
 *
 * @see https://github.com/php/php-src/blob/master/ext/exif/exif.c PHP_FUNCTION(exif_tagname)
 */
final class exif_tagname extends Internal
{
    public function __construct()
    {
        parent::__construct('exif_tagname');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('exif_tagname() accepts exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $index = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'exif_tagname',
            1,
            'index'
        );
        $name = VmExif::tagName($index);
        if (false === $name) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($name);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('exif_tagname() accepts exactly one argument in this compiler build');
        }

        return JitExifTagname::invoke($context, $args[0]);
    }
}
