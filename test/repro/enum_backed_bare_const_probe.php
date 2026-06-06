<?php
enum E: string {
    case A = 'a';
    const X = 1;
}
echo E::X;
