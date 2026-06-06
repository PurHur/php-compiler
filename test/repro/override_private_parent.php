<?php
class Base {
    private function hidden(): void {}
}
class Child extends Base {
    #[\Override]
    public function hidden(): void {}
}
