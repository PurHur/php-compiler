<?php

declare(strict_types=1);

/**
 * #29276 AOT probe — non-literal empty needle.
 * Uncaught ValueError text must still be Zend's "cannot be empty".
 */
$empty = substr('x', 1);
substr_count('abc', $empty);
