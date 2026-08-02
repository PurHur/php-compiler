<?php
// Repro for #26788 — Closure::fromCallable AOT silent empty / null
echo Closure::fromCallable('strlen')('abcd'), "\n";
echo Closure::fromCallable(['DateTime', 'createFromFormat'])('Y-m-d', '2020-01-02')->format('Y-m-d'), "\n";
