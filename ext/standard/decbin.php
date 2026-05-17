<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

/**
 * decbin() implemented as compiled PHP (subset: non-negative integers).
 */
function decbin(int $num): string
{
    if (0 === $num) {
        return '0';
    }
    $result = '';
    for ($n = $num; $n > 0; $n = intval($n / 2)) {
        $result = strval($n % 2) . $result;
    }

    return $result;
}
