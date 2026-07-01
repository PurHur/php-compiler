<?php

// Compile-only (#14613): session_cache_expire() JIT/AOT lowering links on user-script path.
echo session_cache_expire(), "\n";
session_cache_expire(240);
echo session_cache_expire(), "\n";
