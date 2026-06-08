<?php
readonly class R {
    public function __construct(public int $x) {}
}
echo (new R(5))->x, "\n";
