<?php
declare(strict_types=1);

interface Labeled { public function tag(): string; }
enum Status implements Labeled {
    case Open;
    public function tag(): string { return 'open'; }
}
echo Status::Open instanceof Labeled ? '1' : '0';
