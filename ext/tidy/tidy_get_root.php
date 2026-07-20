<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_get_root() — host bridge (php-src ext/tidy/tidy.c; #21543). */
final class tidy_get_root extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_get_root');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_get_root', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTidy::requireTidyObject($frame->calledArgs[0], 'tidy_get_root', 0);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('tidy_get_root() requires a VM context');
        }
        VmTidy::assignNullableNode(
            $frame->returnVar,
            VmTidy::getDocumentNode($ctx, $object, 'root', $frame)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_get_root() is not implemented for JIT in this compiler build (issue #21543)');
    }
}
