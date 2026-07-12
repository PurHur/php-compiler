<?php
declare(strict_types=1);

/**
 * Issue #17522 — AOT abort when echo concat chain has 3+ segments with ?? and trailing variable.
 */
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
echo 'REQUEST_URI='.($_SERVER['REQUEST_URI'] ?? '/')."\n"
    .'SCRIPT_NAME='.($_SERVER['SCRIPT_NAME'] ?? '/example.php')."\n"
    .'PATH_INFO='.$pathInfo."\n";
