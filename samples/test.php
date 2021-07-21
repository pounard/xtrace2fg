<?php

global $ab;
global $abb;
global $ac;

function ABB() {
    global $abb;

    for ($i = 0; $i < 1000; $i++) {
        $abb .= 'a';
    }
}

function ABA() {
}

function AC() {
    global $ac;

    for ($i = 0; $i < 10000; $i++) {
        $ac .= 'b';
    }
}

function AB() {
    global $ab;

    for ($i = 0; $i < 1000; $i++) {
        $ab .= 'c';
    }

    ABA();
    ABB();
}

function AA() {
}

function A() {
    AA();
    AB();
    AA();
    AB();
    AC();
}

A();

// Prevent optimisations.
echo $ab, $abb, $ac, "\n";
