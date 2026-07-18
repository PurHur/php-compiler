<?php

declare(strict_types=1);

/**
 * Repro for #6574 — APCu fetch/store/delete/clear_cache.
 */

echo function_exists('apcu_fetch') ? "fetch=1\n" : "fetch=0\n";
echo function_exists('apcu_store') ? "store=1\n" : "store=0\n";
echo function_exists('apcu_delete') ? "delete=1\n" : "delete=0\n";
echo function_exists('apcu_clear_cache') ? "clear=1\n" : "clear=0\n";
echo function_exists('apcu_exists') ? "exists=1\n" : "exists=0\n";
echo function_exists('apcu_cache_info') ? "info=1\n" : "info=0\n";
echo extension_loaded('apcu') ? "ext=1\n" : "ext=0\n";

apcu_clear_cache();
$ok = apcu_store('k', ['a' => 1], 60);
echo $ok ? "stored=1\n" : "stored=0\n";
$success = false;
$got = apcu_fetch('k', $success);
echo $success ? "success=1\n" : "success=0\n";
echo is_array($got) && ($got['a'] ?? null) === 1 ? "roundtrip=1\n" : "roundtrip=0\n";
echo apcu_exists('k') ? "exists_k=1\n" : "exists_k=0\n";
echo apcu_delete('k') ? "deleted=1\n" : "deleted=0\n";
echo apcu_exists('k') ? "exists_after=1\n" : "exists_after=0\n";
apcu_store('x', 'y');
apcu_clear_cache();
echo apcu_exists('x') ? "clear_fail=1\n" : "clear_ok=1\n";
$info = apcu_cache_info(true);
echo is_array($info) && isset($info['num_entries']) ? "info_ok=1\n" : "info_ok=0\n";
