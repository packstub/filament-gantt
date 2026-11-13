<?php

namespace Packstub\Gantt\Enums;

/** What a parent task draws behind its children, on a chart whose rows are parent tasks (see Gantt::parentSpan()). */
enum ParentSpan: string
{
    /** The parent's own start and end; a parent without dates is a titled row only. */
    case Own = 'own';

    /** From the earliest child's start to the latest child's end: computed, nothing stored, never resized. */
    case FromChildren = 'children';

    /** Nothing: a titled row, whatever dates the parent has. */
    case None = 'none';
}
