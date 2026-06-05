<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

/**
 * filter_var() validation semantics — shared VM/JIT/AOT source (issue #6082).
 *
 * php-src: ext/filter/filter.c — delegates to ext/standard until #6028 relocation completes.
 */
final class VmFilter extends \PHPCompiler\ext\standard\VmFilter
{
}
