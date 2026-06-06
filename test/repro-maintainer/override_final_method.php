<?php

declare(strict_types=1);

class Base {
    final public function foo(): void {}
}
class Child extends Base {
    public function foo(): void {}
}
new Child;
