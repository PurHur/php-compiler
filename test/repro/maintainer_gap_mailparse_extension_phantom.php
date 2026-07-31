<?php

declare(strict_types=1);

// Maintainer repro: #24908 — mailparse phantom on default profile (Zend without pecl-mailparse).
echo 'ext=', extension_loaded('mailparse') ? '1' : '0', "\n";
echo 'fn=', function_exists('mailparse_msg_create') ? '1' : '0', "\n";
