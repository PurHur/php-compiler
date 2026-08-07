<?php
declare(strict_types=1);

/** Issue #28366 — getmygrgid() phantom vs getmygid() on PROFILE≥8.4. */
echo 'getmygrgid=', function_exists('getmygrgid') ? '1' : '0', "\n";
echo 'getmygid=', function_exists('getmygid') ? '1' : '0', "\n";
