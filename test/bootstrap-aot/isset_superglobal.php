<?php

declare(strict_types=1);

/**
 * isset() on superglobal with literal key (IssetHelper + SuperglobalInit compile-time fold).
 */
echo isset($_GET['missing']) ? '1' : '0';
