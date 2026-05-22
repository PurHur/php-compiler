--TEST--
AOT: method with : string return type returns literal
--FILE--
<?php
declare(strict_types=1);
class C {
    private function label(): string { return 'ok'; }
    public function run(): void { echo $this->label(), "\n"; }
}
(new C())->run();
--EXPECT--
ok
--EXPECT_EXIT--
0
