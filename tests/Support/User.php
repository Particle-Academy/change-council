<?php

declare(strict_types=1);

namespace ChangeCouncil\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/** A host user, for the HTTP tests. */
final class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
