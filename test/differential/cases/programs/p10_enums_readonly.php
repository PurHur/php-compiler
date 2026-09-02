<?php
// #36221 program: backed enums + readonly props + match
enum Status: string {
    case Open = 'open';
    case Closed = 'closed';
    case Held = 'held';
    public function label(): string {
        return match ($this) {
            self::Open => 'OPEN',
            self::Closed => 'CLOSED',
            self::Held => 'HELD',
        };
    }
}
readonly class Ticket {
    public function __construct(
        public string $id,
        public Status $status,
        public int $priority,
    ) {}
    public function line(): string {
        return $this->id . ':' . $this->status->value . ':' . $this->status->label() . ':p' . $this->priority;
    }
}
$tickets = [
    new Ticket('t1', Status::Open, 2),
    new Ticket('t2', Status::from('held'), 1),
    new Ticket('t3', Status::Closed, 3),
];
usort($tickets, static function (Ticket $a, Ticket $b) {
    return $a->priority <=> $b->priority;
});
$lines = [];
foreach ($tickets as $t) { $lines[] = $t->line(); }
foreach (Status::cases() as $c) { $lines[] = 'case=' . $c->name . '/' . $c->value; }
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
