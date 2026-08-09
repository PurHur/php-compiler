<?php

declare(strict_types=1);

/**
 * #29268 AOT probe — non-literal empty path (literal '' aborts `phpc build` via rejectEmpty).
 * Uncaught ValueError text must still be Zend's "Path must not be empty".
 */
$empty = substr('x', 1);
fopen($empty, 'r');
