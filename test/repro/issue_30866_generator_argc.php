<?php
function g() { yield 1; return 2; }
$g = g(); $g->next();
try { var_export($g->getReturn(1)); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
function h() { try { yield 1; } catch (Throwable $e) { echo "caught\n"; } }
$h = h(); $h->current();
try { $h->throw(new Exception("x"), 1); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
