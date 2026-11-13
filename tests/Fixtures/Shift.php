<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dates with a time: `datetime` casts, the end is a moment. */
class Shift extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /** The shift this one sits under (rowBy('parent')). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class);
    }
}
