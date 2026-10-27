<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A consumer agent and the packages it declares. A consumer that declares an
 * affected package is a REQUIRED voter; one that only watches it is informed.
 * Internal consumers (our own apps) are never petitioned.
 *
 * @property string $address
 * @property array $packages
 * @property array $watches
 * @property string $silence_policy escalate | proceed_and_notify
 * @property bool $whitelisted
 * @property bool $internal
 */
final class Consumer extends Model
{
    public const POLICIES = ['escalate', 'proceed_and_notify'];

    protected $table = 'council_consumers';

    protected $guarded = [];

    protected $attributes = [
        'watches' => '[]',
        'silence_policy' => 'escalate',
    ];

    protected function casts(): array
    {
        return [
            'packages' => 'array',
            'watches' => 'array',
            'whitelisted' => 'boolean',
            'internal' => 'boolean',
            'reachable' => 'boolean',
            'census_at' => 'datetime',
        ];
    }

    /** The declared entry for a package, or null. */
    public function declares(string $ecosystem, string $name): ?array
    {
        foreach ($this->packages as $p) {
            if (($p['ecosystem'] ?? null) === $ecosystem && ($p['name'] ?? null) === $name) {
                return $p;
            }
        }

        return null;
    }

    public function watchesPackage(string $ecosystem, string $name): bool
    {
        foreach ($this->watches as $p) {
            if (($p['ecosystem'] ?? null) === $ecosystem && ($p['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
}
