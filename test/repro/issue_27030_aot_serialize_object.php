<?php
// repro #27030 — AOT serialize(new C) must not segfault; full roundtrip follow-up
class C { public $a = 1; }
echo serialize(new C), PHP_EOL;
