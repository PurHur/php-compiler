<?php
declare(strict_types=1);

// Repro for #25485 — date_parse / getLastErrors HashTable key order vs Zend.
echo implode('|', array_keys(date_parse('2020-01-02 03:04:05'))), "\n";
echo implode('|', array_keys(date_parse('2020-01-02 03:04:05 UTC'))), "\n";
echo implode('|', array_keys(date_parse('2024-01-01T12:00:00+02:00'))), "\n";

DateTime::createFromFormat('Y-m-d', 'not-a-date');
echo implode('|', array_keys(DateTime::getLastErrors())), "\n";
