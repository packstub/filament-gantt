<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Whole-day dates: `date` casts, the end day included in the bar. */
class Task extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Not a column the tests create: only there to be refused by groupBy(). */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
