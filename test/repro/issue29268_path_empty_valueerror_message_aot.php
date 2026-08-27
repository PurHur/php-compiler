<?php

declare(strict_types=1);

/**
 * #29268 AOT probe — non-literal empty path. Literal '' also compiles (runtime rejectEmpty branch).
 * Uncaught ValueError text must still be Zend's "Path must not be empty".
 */
$empty = substr('x', 1);
fopen($empty, 'r');
