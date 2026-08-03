<?php
// Repro #27331 — AOT #[\Deprecated] method must emit E_USER_DEPRECATED like VM/JIT.
class C {
    #[\Deprecated(message: 'use g()', since: '8.4')]
    public function f(): int { return 1; }
}
echo (new C())->f(), "\n";
