<?php
class N {
    public function g(): ?N {
        return $this;
    }

    public function h(): int {
        return 1;
    }
}

$n = new N();
echo 'live:', $n?->g()?->h(), "\n";

$null = null;
echo 'null:', ($null?->g()?->h() ?? 'null'), "\n";
