<?php
// Repro #27331 — AOT #[\Deprecated] class constant must emit E_USER_DEPRECATED like VM/JIT.
class C {
    #[\Deprecated(message: 'use NEW', since: '8.4')]
    public const A = 1;
}
echo C::A, "\n";
