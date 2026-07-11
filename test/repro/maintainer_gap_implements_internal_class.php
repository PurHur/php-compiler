<?php
declare(strict_types=1);
class C implements Closure {
    public function __invoke(): void {}
}
echo "fail: Closure implements accepted\n";
