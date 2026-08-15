<?php

declare(strict_types=1);

// Compile-only (#28404): header_list() must stay unregistered (php-src has headers_list only).
echo function_exists('header_list') ? "yes\n" : "no\n";
echo function_exists('headers_list') ? "headers-yes\n" : "headers-no\n";
