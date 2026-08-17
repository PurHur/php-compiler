<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** Imagick::__construct(?string $files = null) — VM (#6235). */
final class ImagickConstruct extends ImagickClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Imagick::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('Imagick::__construct() must be called on Imagick');
        }
        $object = $var->toObject();
        VmImagick::initObject($object);
        if (\count($frame->calledArgs) >= 2 && Variable::TYPE_UNDEFINED !== $frame->calledArgs[1]->type) {
            $files = $this->stringArg($frame->calledArgs[1], 'Imagick::__construct', 1, 'files');
            if ('' !== $files) {
                VmImagick::readImage($object, $files);
            }
        }
    }
}
