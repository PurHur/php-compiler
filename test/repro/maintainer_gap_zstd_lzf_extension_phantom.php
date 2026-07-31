<?php

declare(strict_types=1);

// Maintainer repro: #25287 — zstd/lzf phantom on default profile (Zend without PECL).
echo 'zstd_ext=', extension_loaded('zstd') ? '1' : '0', "\n";
echo 'zstd_fn=', function_exists('zstd_compress') ? '1' : '0', "\n";
echo 'lzf_ext=', extension_loaded('lzf') ? '1' : '0', "\n";
echo 'lzf_fn=', function_exists('lzf_compress') ? '1' : '0', "\n";
