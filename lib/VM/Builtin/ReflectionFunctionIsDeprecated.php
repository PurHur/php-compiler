<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::isDeprecated() — VM (#9760, #28172, ext/reflection/php_reflection.c). */
final class ReflectionFunctionIsDeprecated extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDeprecated');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        if (!CompilerVersion::supportsReflectionFunctionIsDeprecated()) {
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = VmReflection::requireContext($frame);
        if (ReflectionSupport::isReflectionInternalFunction($receiver)) {
            // Stub #[\Deprecated] on internals (xml_set_object; php-src xml.stub.php; #28172).
            $internal = ReflectionSupport::resolveFunctionForReflection(
                $ctx,
                ReflectionSupport::functionNameFromReflection($receiver)
            );
            $meta = $internal instanceof \PHPCompiler\Func\Internal ? $internal->deprecated : null;
            $frame->returnVar->bool(null !== $meta && $meta->isDeprecatedForReflection());

            return;
        }
        $func = ReflectionSupport::resolveFunctionFromReflection($ctx, $receiver);
        $deprecated = $func->deprecated;
        $frame->returnVar->bool(
            null !== $deprecated && $deprecated->isDeprecatedForReflection()
        );
    }
}
