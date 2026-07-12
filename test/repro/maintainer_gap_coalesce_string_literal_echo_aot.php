<?php
declare(strict_types=1);

/**
 * Bootstrap/AOT: ?? string-literal RHS then echo must not reuse non-dominating string SSA (#1492).
 */
$name = $x ?? '';
echo 'hi';
