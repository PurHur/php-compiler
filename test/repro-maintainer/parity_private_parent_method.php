<?php

class Base {
    private function secret(): string {
        return 'base';
    }

    protected function prot(): string {
        return 'prot';
    }
}

class Child extends Base {
    public function callStatic(): void {
        try {
            echo Base::secret(), "\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }

    public function callInst(): void {
        try {
            echo $this->secret(), "\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
    }

    public function callProt(): void {
        echo $this->prot(), "\n";
    }
}

$c = new Child();
$c->callStatic();
$c->callInst();
$c->callProt();
