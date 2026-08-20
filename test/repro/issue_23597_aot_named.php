<?php
/**
 * #23597 AOT — named stub params must lower (compile + link).
 * Runtime values for shell_exec/disk_* under thin AOT are pre-existing stubs on master;
 * this guard only asserts named Zend names are accepted at compile time.
 */
echo 'compiled_named_ok', "\n";
$_ = shell_exec(command: 'true');
$_ = disk_free_space(directory: '/');
$_ = disk_total_space(directory: '/');
