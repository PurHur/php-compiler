<?php

declare(strict_types=1);

/**
 * #29275 AOT probe — non-literal empty separator (literal '' may abort compile-time rejectEmpty).
 * Uncaught ValueError text must still be Zend's "cannot be empty".
 */
$empty = substr('x', 1);
explode($empty, 'a,b');
