<?php

namespace App\Support;

/**
 * Holds the institute (tenant) for the current request. Set by the
 * EnsureTenant middleware from the authenticated user; read by the
 * BelongsToInstitute global scope so every tenant query is isolated.
 */
class Tenant
{
    private ?int $instituteId = null;

    public function set(?int $instituteId): void
    {
        $this->instituteId = $instituteId;
    }

    public function id(): ?int
    {
        return $this->instituteId;
    }

    public function has(): bool
    {
        return $this->instituteId !== null;
    }
}
