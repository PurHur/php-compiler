<?php

// Compile-only (#11095): session_cache_limiter() VM builtin links on user-script AOT path.
$prev = session_cache_limiter('public');
$current = session_cache_limiter();
echo function_exists('session_cache_limiter') ? 'yes' : 'no', "\n";
echo $prev, "\n";
echo $current, "\n";
