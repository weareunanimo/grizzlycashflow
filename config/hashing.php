<?php

declare(strict_types=1);

use Illuminate\Support\Env;

/**
 * Argon2id como padrão (ADR-0009) — resistente a GPU, ao contrário do bcrypt puro.
 * Parâmetros conforme docs/11-seguranca.md#1: m=64MB, t=4, p=1.
 */
return [
    'driver' => Env::get('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => Env::get('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    'argon' => [
        'memory' => 65536, // KB = 64 MB
        'threads' => 1,
        'time' => 4,
        'verify' => true,
    ],

    'rehash_on_login' => true,
];
