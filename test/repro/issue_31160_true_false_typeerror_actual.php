<?php
// @differential-skip-aot: TypeError message text; VM/JIT covered by compliance phpt
function f(true $x) { return $x; }
try { f(false); } catch (Throwable $e) { echo $e->getMessage(), "\n"; }
function g(): true { return false; }
try { g(); } catch (Throwable $e) { echo $e->getMessage(), "\n"; }
function h(false $x) {}
try { h(true); } catch (Throwable $e) { echo $e->getMessage(), "\n"; }
function i(true $x) {}
try { i(0); } catch (Throwable $e) { echo $e->getMessage(), "\n"; }
try { i('1'); } catch (Throwable $e) { echo $e->getMessage(), "\n"; }
