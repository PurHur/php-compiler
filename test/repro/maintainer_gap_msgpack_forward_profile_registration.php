<?php

declare(strict_types=1);

$profile = getenv('PHP_COMPILER_PROFILE');
if (!is_string($profile) || '8.4' !== $profile) {
    echo "skip: msgpack forward profile requires PHP_COMPILER_PROFILE=8.4\n";
    exit(0);
}

require __DIR__.'/maintainer_gap_msgpack_forward_profile_roundtrip.php';
