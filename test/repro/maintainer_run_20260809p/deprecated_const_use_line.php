<?php
class C {
    #[\Deprecated(message: 'use NEW', since: '8.4')]
    public const OLD = 1;
}

echo C::OLD, "\n";
