<?php
// Concrete class uses LoggerTrait directly — composing has log() (#36382 regression guard).
trait LoggerTrait {
    public function error(string $message): void {
        $this->log('error', $message);
    }
    abstract public function log(string $level, string $message): void;
}
class Logger {
    use LoggerTrait;
    public function log(string $level, string $message): void {
        echo $level, ':', $message;
    }
}
(new Logger())->error('boom');
