<?php
trait T { public function f(): void {} }
class C { use T; #[\Override] public function f(): void {} }
echo "ok\n";
