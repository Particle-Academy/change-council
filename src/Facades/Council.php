<?php

declare(strict_types=1);

namespace ChangeCouncil\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ChangeCouncil\Ledger\Ledger ledger()
 * @method static \ChangeCouncil\Petitions\Ingest ingest()
 * @method static void authorizeUsing(\Closure $callback)
 * @method static void routes(array $options = [])
 *
 * @see \ChangeCouncil\Council
 */
final class Council extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ChangeCouncil\Council::class;
    }
}
