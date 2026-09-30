<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Dates with a time: `datetime` casts, the end is a moment. */
class Shift extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}
