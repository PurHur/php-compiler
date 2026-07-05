<?php

interface I {
    public function m(): void;
}

echo method_exists(I::class, 'm') ? "ok\n" : "fail\n";
