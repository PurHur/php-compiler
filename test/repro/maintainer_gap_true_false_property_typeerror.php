<?php
class TrueP { public true $x; }
class FalseP { public false $y; }
class IntP { public int $z; }

$o = new TrueP();
try { $o->x = false; } catch (Throwable $e) { echo $e->getMessage(), "\n"; }

$o2 = new FalseP();
try { $o2->y = true; } catch (Throwable $e) { echo $e->getMessage(), "\n"; }

$o3 = new IntP();
try { $o3->z = []; } catch (Throwable $e) { echo $e->getMessage(), "\n"; }
