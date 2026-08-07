<?php
declare(strict_types=1);

/** Issue #28365 — class_uses_recursive() phantom vs class_uses() on PROFILE≥8.4. */
echo 'class_uses_recursive=', function_exists('class_uses_recursive') ? '1' : '0', "\n";
echo 'class_uses=', function_exists('class_uses') ? '1' : '0', "\n";
