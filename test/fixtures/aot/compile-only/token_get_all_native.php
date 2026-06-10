<?php

declare(strict_types=1);

// Compile-only (#3171): token_get_all() must lower for AOT via TokenGetAllJitHelper.
$t = token_get_all('<?php echo 1;');
echo ($t[1][0] === T_ECHO ? "ok\n" : "fail\n");
