<?php

namespace SocialDept\AtpParity\Enums;

enum PendingSyncOperation: string
{
    case Sync = 'sync';
    case Resync = 'resync';
    case Unsync = 'unsync';
    case SyncWithReference = 'sync_with_reference';
    case ResyncWithReference = 'resync_with_reference';
    case UnsyncWithReference = 'unsync_with_reference';
}
