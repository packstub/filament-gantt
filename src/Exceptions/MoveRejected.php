<?php

namespace Packstub\Gantt\Exceptions;

use RuntimeException;

/** Thrown to refuse a move or a resize: the bar goes back where it was and the message is shown to the user. */
class MoveRejected extends RuntimeException {}
