<?php

declare(strict_types=1);

// Maintainer repro: #25087 — lz4 phantom on default profile (Zend without pecl-lz4).
echo 'ext=', extension_loaded('lz4') ? '1' : '0', "\n";
echo 'fn=', function_exists('lz4_compress') ? '1' : '0', "\n";
