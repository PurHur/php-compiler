<?php
declare(strict_types=1);

class Base {
    public function foo(): void {}
}

#[\Override]
class Child extends Base {
    public function foo(): void {}
}
