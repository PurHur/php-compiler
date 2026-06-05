<?php
declare(strict_types=1);

interface HasName { public function label(): string; }
enum Status: string implements HasName {
    case Open = 'open';
    public function label(): string { return $this->name; }
}
echo Status::Open instanceof HasName ? '1' : '0';
