<?php

// Compile-only (#6002): session_abort() JIT/AOT lowering links on user-script path.
session_start();
$_SESSION['k'] = 1;
session_abort();
