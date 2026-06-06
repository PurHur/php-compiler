<?php
trait T { public function foo(): void {} }
class C { use T; #[\Override] public function foo(): void {} }
echo "ok\n";
