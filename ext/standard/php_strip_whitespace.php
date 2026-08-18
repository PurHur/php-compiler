<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * php_strip_whitespace() — strip comments/whitespace from PHP source file (#3262).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(php_strip_whitespace)
 * Zend: Zend/zend_highlight.c — zend_strip()
 */
final class php_strip_whitespace extends Internal
{
    public function __construct()
    {
        parent::__construct('php_strip_whitespace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('php_strip_whitespace() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $path = VmStreamPath::coerceNonEmptyPathArgForFrame(
            $frame,
            0,
            'php_strip_whitespace',
            'filename'
        );
        if (VmStreamIncludeOpenPolicy::blockedForScriptOpen($path, $frame->vmContext)) {
            VmStreamIncludeOpenPolicy::warnScriptOpenBlocked($frame, 'php_strip_whitespace', $path);
            $frame->returnVar->string('');

            return;
        }
        $contents = VmFs::fileGetContents($path);
        if (false === $contents) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'php_strip_whitespace', $path);
            $frame->returnVar->string('');

            return;
        }
        $frame->returnVar->string(VmStripWhitespace::stripSource($contents));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitStripWhitespace::invoke($context, ...$args);
    }
}
