<?php

namespace SocialDept\AtpParity\Enums;

enum ValidationMode: string
{
    /**
     * Reject records with unknown fields or constraint violations.
     */
    case Strict = 'strict';

    /**
     * Allow unknown fields, enforce constraints.
     */
    case Optimistic = 'optimistic';

    /**
     * Skip constraint checking, just validate types.
     */
    case Lenient = 'lenient';

    /**
     * Disable validation entirely.
     */
    case Disabled = 'disabled';
}
