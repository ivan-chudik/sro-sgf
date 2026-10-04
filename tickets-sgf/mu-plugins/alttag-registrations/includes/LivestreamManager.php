<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @deprecated Use Module\LivestreamModule instead. Kept for constant references.
 */
class LivestreamManager
{
    public const NOT_GRANTED_STATE = 'not_granted';
    public const GRANTED_STATE = 'granted';
    public const PENDING_STATE = 'pending';
    public const FAILED_STATE = 'failed';
}
