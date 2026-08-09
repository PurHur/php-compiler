<?php

declare(strict_types=1);

/**
 * #29294 AOT probe — non-literal empty path (literal '' aborts `phpc build` via rejectEmpty).
 * Uncaught ValueError text must still be Zend's "Path must not be empty".
 */
$empty = substr('x', 1);
file_put_contents($empty, 'x');
