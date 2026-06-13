<?php
declare(strict_types=1);
// Compile-only (#6002): session_reset() JIT/AOT lowering links on user-script path.
session_start();
session_reset();
