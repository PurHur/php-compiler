<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\CastArrayValueBoxJit;
use PHPCompiler\OpCode;

/**
 * JIT lowering for (array)/(object)/(unset) casts (#4887, #10046, #10244).
 *
 * php-src: Zend/zend_operators.c / ext/standard/type.c
 * SSOT: {@see \PHPCompiler\VM\CastSupport}, {@see \PHPCompiler\VM\CastJitHelper}
 */
final class CastHelper
{
    public static function emitArrayCast(Context $context, Variable $src): Variable
    {
        // Breadcrumb: first TYPE_CAST_ARRAY mid-IncludeHelper can stall minutes while
        // JitGetObjectVarsNative class-id dispatch is inlined (#36382 Slim/FastRoute).
        Progress::noteFunction('cast_array_begin:src_type='.$src->type);
        CastArrayShared::ensureInsertBlock($context, 'cast_array_body');
        if (Variable::TYPE_VALUE === $src->type) {
            $out = CastArrayValueBoxJit::emit($context, $src);
            Progress::noteFunction('cast_array_done:value_box');

            return $out;
        }

        $out = CastArrayNativeJit::emit($context, $src);
        Progress::noteFunction('cast_array_done:native');

        return $out;
    }

    public static function emitObjectCast(
        Context $context,
        Variable $src,
        Block $block,
        OpCode $op
    ): Variable {
        return CastObjectNativeJit::emit($context, $src, $block, $op);
    }

    public static function emitUnsetCast(Context $context, Variable $src): Variable
    {
        return CastUnsetJit::emit($context, $src);
    }
}
