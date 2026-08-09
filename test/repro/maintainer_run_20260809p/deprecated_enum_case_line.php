<?php
enum E {
    #[\Deprecated(message: 'old', since: '8.4')]
    case Old;
    case New;
}
echo E::Old->name, "\n";
