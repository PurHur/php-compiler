<?php
/**
 * Repro: pdo_drivers() procedural alias (#20239).
 * AOT-safe (avoids in_array on constant arrays; compares element directly).
 */
echo (int) function_exists('pdo_drivers'), "\n";
$a = pdo_drivers();
echo count($a), "\n";
echo ($a[0] ?? '') === 'sqlite' ? "sqlite\n" : "no-sqlite\n";
