<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mysqli_get_links_stats() — php-src ext/mysqli/mysqli_nonapi.c (#22183).
 *
 * Returns open/cached link counters: total, active_plinks, cached_plinks.
 */
final class mysqli_get_links_stats extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_get_links_stats');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'mysqli_get_links_stats', 0);
        if (null === $frame->returnVar) {
            return;
        }
        VmMysqli::assignRow($frame->returnVar, VmMysqli::linksStats());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_get_links_stats() is not implemented for JIT (issue #22183)');
    }
}
