<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringImageTypeToExtension;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * image_type_to_extension() — IMAGETYPE_* to file extension (ext/standard/image.c, #6091).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/image.c PHP_FUNCTION(image_type_to_extension)
 */
final class image_type_to_extension extends Internal
{
    public function __construct()
    {
        parent::__construct('image_type_to_extension');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('image_type_to_extension() accepts one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $imageType = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'image_type_to_extension',
            1,
            'image_type'
        );
        $includeDot = true;
        if (2 === $argc) {
            $includeDot = self::coerceIncludeDotOperand(
                $frame->calledArgs[1]->resolveIndirect()
            );
        }
        $ext = VmImage::imageTypeToExtension($imageType, $includeDot);
        if (false === $ext) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($ext);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('image_type_to_extension() accepts one or two arguments in this compiler build');
        }
        $includeDotI8 = $context->getTypeFromString('int8')->constInt(1, false);
        if (2 === $argc) {
            $includeDotBool = JitBoolArg::lower($context, $args[1], 'image_type_to_extension() include_dot');
            $includeDotI8 = $context->builder->zext($includeDotBool, $context->getTypeFromString('int8'));
        }

        StringImageTypeToExtension::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_image_type_to_extension'),
            JitImageTypeArg::lowerImageType($context, $args[0], 'image_type_to_extension'),
            $includeDotI8,
            $outPtr
        );

        return $outPtr;
    }

    private static function coerceIncludeDotOperand(Variable $flag): bool
    {
        switch ($flag->type) {
            case Variable::TYPE_BOOLEAN:
                return $flag->toBool();
            case Variable::TYPE_INTEGER:
                return 0 !== $flag->toInt();
            case Variable::TYPE_FLOAT:
                return 0.0 !== $flag->toFloat();
            case Variable::TYPE_STRING:
                $lower = strtolower($flag->toString());
                if (\in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
                    return true;
                }
                if (\in_array($lower, ['0', 'false', 'off', 'no', ''], true)) {
                    return false;
                }

                throw new \TypeError('image_type_to_extension(): Argument #2 ($include_dot) must be of type bool, string given');
            case Variable::TYPE_ARRAY:
                throw new \TypeError('image_type_to_extension(): Argument #2 ($include_dot) must be of type bool, array given');
            case Variable::TYPE_OBJECT:
                throw new \TypeError('image_type_to_extension(): Argument #2 ($include_dot) must be of type bool, object given');
            case Variable::TYPE_NULL:
                throw new \TypeError('image_type_to_extension(): Argument #2 ($include_dot) must be of type bool, null given');
            default:
                throw new \TypeError('image_type_to_extension(): Argument #2 ($include_dot) must be of type bool, unknown given');
        }
    }
}
