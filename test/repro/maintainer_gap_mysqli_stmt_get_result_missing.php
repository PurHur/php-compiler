<?php

declare(strict_types=1);

/** Repro #22162 — mysqli_stmt_get_result / mysqli_stmt::get_result registration. */
echo 'mysqli_stmt_get_result=', function_exists('mysqli_stmt_get_result') ? 'yes' : 'NO', "\n";
echo 'mysqli_stmt::get_result=', method_exists('mysqli_stmt', 'get_result') ? 'yes' : 'NO', "\n";
