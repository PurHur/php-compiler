<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * imageresolution() — get/set image DPI (php-src ext/gd/gd.c; #20430).
 */
final class imageresolution extends Internal
{
    public function __construct()
    {
        parent::__construct('imageresolution');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('imageresolution() expects 1 to 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageresolution', 1);
        $resXNull = true;
        $resYNull = true;
        $resX = 0;
        $resY = 0;
        if ($argc >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $resXNull = false;
                $resX = VmGd::coerceIntArg($arg, 'imageresolution', 2, 'resolution_x');
            }
        }
        if ($argc >= 3) {
            $arg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $resYNull = false;
                $resY = VmGd::coerceIntArg($arg, 'imageresolution', 3, 'resolution_y');
            }
        }

        if (!$resXNull && !$resYNull) {
            self::assertResolutionInRange($resX, 2, 'resolution_x');
            self::assertResolutionInRange($resY, 3, 'resolution_y');
            $frame->returnVar->bool(VmGd::setResolution($image, $resX, $resY));

            return;
        }
        if (!$resXNull && $resYNull) {
            self::assertResolutionInRange($resX, 2, 'resolution_x');
            $frame->returnVar->bool(VmGd::setResolution($image, $resX, $resX));

            return;
        }
        if ($resXNull && !$resYNull) {
            self::assertResolutionInRange($resY, 3, 'resolution_y');
            $frame->returnVar->bool(VmGd::setResolution($image, $resY, $resY));

            return;
        }

        $pair = VmGd::getResolution($image);
        if (null === $pair) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmGd::resolutionToHashTable($pair[0], $pair[1]));
    }

    private static function assertResolutionInRange(int $value, int $position, string $name): void
    {
        if ($value < 0 || $value > VmGd::RESOLUTION_UINT_MAX) {
            throw new \ValueError(\sprintf(
                'imageresolution(): Argument #%d ($%s) must be between 0 and %u',
                $position,
                $name,
                VmGd::RESOLUTION_UINT_MAX
            ));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageresolution() is VM-only in this compiler build (#20430)');
    }
}
