<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_extension_funcs() — list functions registered by an extension (#3433, ext/standard/basic_functions.c).
 *
 * Z_PARAM_STR $extension — null TypeError on PHP_COMPILER_PROFILE=8.4 (#20254).
 * Stub name is $extension (php-src basic_functions.stub.php); InternalArgInfo still says extension_name (#23569).
 * Excess/missing argc → Zend ArgumentCountError (#30784).
 */
final class get_extension_funcs extends Internal
{
    public function __construct()
    {
        parent::__construct('get_extension_funcs');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#30784).
        $this->requireExactArgCount($frame, 'get_extension_funcs', 1);
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR — null TypeError on PROFILE=8.4 (#20254, ext/standard/info.c).
        $name = VmString::zparamStrBuiltinArgForFrame(
            $frame,
            0,
            'get_extension_funcs',
            0,
            'extension'
        );
        $funcs = VmInfo::get_extension_funcs($name);
        if (false === $funcs) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($funcs);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'get_extension_funcs', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitInfo::get_extension_funcs($context, $args[0]);
    }
}
