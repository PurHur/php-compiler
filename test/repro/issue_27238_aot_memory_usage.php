<?php
// Issue #27238 — AOT memory_get_usage / memory_get_peak_usage must be > 0.
echo memory_get_usage() > 0 ? 'ok' : 'bad', '|', memory_get_usage(), "\n";
echo memory_get_peak_usage() > 0 ? 'ok' : 'bad', '|', memory_get_peak_usage(), "\n";
$a = str_repeat('x', 10000);
echo memory_get_peak_usage() >= memory_get_usage() ? 'peak_ok' : 'peak_bad', "\n";
