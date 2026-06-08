<?php
// AOT compile-only: serialize()/unserialize() enum case (#6131).
enum U { case A; }
enum I: int { case N = 42; }

$u = U::A;
$i = I::N;
serialize($u);
unserialize(serialize($u));
serialize($i);
unserialize(serialize($i));
