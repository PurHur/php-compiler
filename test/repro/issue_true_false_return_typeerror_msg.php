<?php
function badTrue(): true { return false; }
try { badTrue(); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
class C { public function badFalse(): false { return true; } }
try { (new C)->badFalse(); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
function badInt(): int { return []; }
try { badInt(); } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
