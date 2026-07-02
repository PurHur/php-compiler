<?php

interface I {
    public function f(): void {
        echo "unreachable\n";
    }
}

echo "unreachable\n";
