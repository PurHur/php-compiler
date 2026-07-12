<?php

declare(strict_types=1);

namespace PHPCompiler\ext\exif;

use PHPCompiler\JIT\Builtin\StringExifImagetype;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStreamPath;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for exif_imagetype() via ExifImagetypeJitHelper (#18181). */
final class JitExifImagetype
{
    /** @param list<JITVariable> $args */
    public static function invoke(Context $context, array $args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'exif_imagetype() expects exactly 1 argument, %d given',
                \count($args)
            ));
        }

        $pathLit = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null !== $pathLit) {
            $type = VmExifRead::imageType($pathLit);
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            if (false === $type) {
                $i1 = $context->getTypeFromString('int1');
                JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
            } else {
                JitValueBox::writeLong(
                    $context,
                    $slot,
                    $context->getTypeFromString('int64')->constInt((int) $type, false)
                );
            }

            return $ptr;
        }

        $path = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'exif_imagetype', 0, 'filename');

        return StringExifImagetype::invoke($context, $path);
    }
}
