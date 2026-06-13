<?php
declare(strict_types=1);
// Compile-only (#6002): session_create_id() JIT/AOT lowering links on user-script path.
session_create_id();
session_create_id('app-');
