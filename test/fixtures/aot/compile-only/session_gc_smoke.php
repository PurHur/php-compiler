<?php
declare(strict_types=1);
// Compile-only (#6006): session_gc() JIT/AOT lowering links on user-script path.
session_start();
session_gc();
