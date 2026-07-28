<?php
// #24105: `finally` never ran, and its presence stopped `catch` running — the exception escaped.
// Fixed for these straight-line shapes; kept as a guard. See j05 for the case still open.
try { echo "a"; } finally { echo "b"; }
try { throw new RuntimeException("x"); } catch (RuntimeException $e) { echo "c"; }
try { throw new RuntimeException("y"); } catch (RuntimeException $e) { echo "d"; } finally { echo "e"; }
echo "\n";
