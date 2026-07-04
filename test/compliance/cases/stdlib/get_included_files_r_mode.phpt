--TEST--
get_included_files() / get_required_files() — php -r mode returns empty list (#10279, basic_functions.c)
--FILE--
<?php
$repoRoot = dirname(__DIR__, 3);
$vm = $repoRoot . '/bin/vm.php';
$code = 'echo json_encode(get_included_files()), "\n", json_encode(get_required_files()), "\n";';
$out = shell_exec(PHP_BINARY . ' -n -r ' . escapeshellarg($code));
$vmOut = shell_exec(PHP_BINARY . ' ' . escapeshellarg($vm) . ' -r ' . escapeshellarg($code));
echo trim((string) $out) === "[]\n[]" && trim((string) $vmOut) === "[]\n[]" ? "ok\n" : "fail zend=$out vm=$vmOut\n";
--EXPECT--
ok
