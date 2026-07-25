<?php

declare(strict_types=1);

/**
 * LLVM lowering for dynamic class constant fetch `Class::{$name}` (issue #3150).
 *
 * php-src: {@see https://github.com/php/php-src/blob/master/Zend/zend_compile.c}
 * runtime lookup by name in {@see https://github.com/php/php-src/blob/master/Zend/zend_execute.c}
 */

namespace PHPCompiler\JIT;

// Spine inventory is alphabetical (Helper before HelperTrait). Require the trait here so
// Zend full-spine AOT cannot hit "Trait not found" when composer autoload is not in play (#22642).
require_once __DIR__ . '/ClassConstFetchHelperTrait.php';

/**
 * Intentionally small entrypoint: the lowering bodies live in {@see ClassConstFetchHelperTrait}
 * to keep the helper file narrow while the php-in-php migration iterates (#10200).
 */
final class ClassConstFetchHelper
{
    use ClassConstFetchHelperTrait;
}
