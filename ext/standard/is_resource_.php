<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * is_resource() — stream handle detection (ext/standard/basic_functions.c parity, #3519).
 *
 * VM: {@see Variable::streamHandle()} tags fopen() results so plain integers stay false.
 * JIT/AOT: {@see __compiler_is_resource} checks the native stream handle table.
 */
final class is_resource_ extends Internal
{
    public function __construct()
    {
        parent::__construct('is_resource');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_resource() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(self::isResource($v));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('is_resource() requires exactly one argument');
        }
        if (0 !== ($args[0]->type & JITVariable::IS_NATIVE_ARRAY)) {
            return $context->constantFromBool(false);
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type) {
            return JitStreamContextRepresentation::isRepresentationArg($context, $args[0]);
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            $streamCtx = JitStreamContextRepresentation::isRepresentationArg($context, $args[0]);
            \PHPCompiler\JIT\Builtin\StringDir::ensureLinked($context);
            $handleRes = JitResourceArg::lowerIsResource($context, $args[0]);

            return $context->builder->or($streamCtx, $handleRes);
        }
        \PHPCompiler\JIT\Builtin\StringDir::ensureLinked($context);

        return JitResourceArg::lowerIsResource($context, $args[0]);
    }

    public static function isResource(Variable $v): bool
    {
        if ($v->isStreamResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);

            return null !== $handle
                && (
                    VmFs::isValidHandle($handle)
                    || VmFs::isFailedStreamHandle($handle)
                    || VmFs::isZipArchivePlaceholder($handle)
                    || VmFs::isZipEntryPlaceholder($handle)
                );
        }
        if ($v->isDirResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);

            return null !== $handle && VmDir::isValidHandle($handle);
        }
        if ($v->isBrigadeResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);

            return null !== $handle && VmStreamBucket::isValidBrigade($handle);
        }
        if ($v->isBucketResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);

            return null !== $handle && VmStreamBucket::isValidBucket($handle);
        }
        if ($v->isStreamFilterResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);

            return null !== $handle && VmStreamFilterChain::isValidFilter($handle);
        }
        if ($v->isProcessResource()) {
            $handle = \PHPCompiler\VM\ResourceSupport::resolveHandle($v);

            return null !== $handle && VmProcess::isValidHandle($handle);
        }
        if (\PHPCompiler\VM\ResourceSupport::isStreamContextResource($v)) {
            return true;
        }
        if (\PHPCompiler\VM\ResourceSupport::isWddxPacketResource($v)) {
            return true;
        }

        return false;
    }
}
