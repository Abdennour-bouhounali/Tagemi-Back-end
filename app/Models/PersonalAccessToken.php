<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum saves last_used_at on every request, and staff screens poll: at most
 * one write per token per minute is enough.
 */
class PersonalAccessToken extends SanctumToken
{
    private const LAST_USED_PRECISION_SECONDS = 60;

    public function save(array $options = [])
    {
        $previous = $this->getOriginal('last_used_at');

        if ($this->exists && $previous && array_keys($this->getDirty()) === ['last_used_at']
            && $previous->gt(now()->subSeconds(self::LAST_USED_PRECISION_SECONDS))) {
            return true;
        }

        return parent::save($options);
    }
}
