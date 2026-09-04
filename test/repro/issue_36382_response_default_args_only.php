<?php
declare(strict_types=1);
final class RespDef36382 {
    private int $status;
    public function __construct(int $status = 200, array $headers = [], $body = null) {
        $this->status = $status;
    }
    public function get(): int { return $this->status; }
}
echo (new RespDef36382())->get(), '|', (new RespDef36382(404))->get(), "\n";
