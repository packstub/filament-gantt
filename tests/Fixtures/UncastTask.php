<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** The tasks table without casts: DATE columns read as plain `Y-m-d` strings. */
class UncastTask extends Model
{
    public $timestamps = false;

    protected $table = 'tasks';

    protected $guarded = [];
}
