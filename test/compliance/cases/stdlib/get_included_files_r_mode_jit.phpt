--JIT--
--TEST--
get_included_files() JIT — php -r mode returns empty list (#10279)
--FILE--
<?php
$repoRoot = dirname(__DIR__, 3);
$vm = $repoRoot . '/bin/vm.php';
$code = 'echo json_encode(get_included_files()), "\n", json_encode(get_required_files()), "\n";';
$vmOut = shell_exec(PHP_BINARY . ' ' . escapeshellarg($vm) . ' -r ' . escapeshellarg($code));
echo trim((string) $vmOut) === "[]\n[]" ? "ok\n" : "fail vm=$vmOut\n";
--EXPECT--
ok
