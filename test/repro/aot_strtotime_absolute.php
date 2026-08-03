<?php
// #27091 — AOT strtotime absolute date (i1 vs i64 hasBase ABI)
echo date('Y-m-d', strtotime('2024-08-02')), "\n";
