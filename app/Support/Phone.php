<?php

namespace App\Support;

use LogicException;

final class Phone
{
    public const PATTERN = '/^\+[1-9][0-9]{7,14}$/D';

    public const RULE = 'regex:'.self::PATTERN;

    public static function fingerprint(string $phone): string
    {
        $key = config('dag.phone_key');

        if (! is_string($key) || strlen($key) < 32) {
            throw new LogicException('Configure DAG_PHONE_KEY com uma chave de pelo menos 32 caracteres.');
        }

        return hash_hmac('sha256', $phone, $key);
    }
}
