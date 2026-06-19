<?php
interface A {}
interface B {}
class C implements A, B {}

function acceptsUnion((string|int) $x): void { echo $x, "\n"; }
function acceptsIface((A|B) $x): void { echo "ok\n"; }

acceptsUnion(1);
acceptsIface(new C());
