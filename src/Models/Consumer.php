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
 * @property int $weight vote weight, >= 0; 0 is advisory (never blocks on its own weight)
 * @property ?string $project_name
 * @property ?string $project_description
 * @property ?string $project_url http or https only
 * @property ?\Illuminate\Support\Carbon $removed_at the owner removed this member; the row is kept
 */
final class Consumer extends Model
{
    public const POLICIES = ['escalate', 'proceed_and_notify'];

    public const PROJECT_FIELDS = ['project_name', 'project_description', 'project_url'];

    protected $table = 'council_consumers';

    protected $guarded = [];

    protected $attributes = [
        'watches' => '[]',
        'silence_policy' => 'escalate',
        // No `weight` here: the column's own default (1) supplies it. Naming it
        // would put it in every insert, and a host migration that seeds
        // consumers before the 0.5 migration has run would then fail. The same
        // holds for `removed_at` (0.5.1).
    ];

    /** A URL that may be drawn as a link: absolute http or https with a host, no whitespace. */
    public static function safeUrl(mixed $url): bool
    {
        return is_string($url)
            && preg_match('#^https?://[^\s/?\#]+[^\s]*$#i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_string(parse_url($url, PHP_URL_HOST)) && parse_url($url, PHP_URL_HOST) !== '';
    }

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'packages' => 'array',
            'watches' => 'array',
            'whitelisted' => 'boolean',
            'internal' => 'boolean',
            'reachable' => 'boolean',
            'census_at' => 'datetime',
            'removed_at' => 'datetime',
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
