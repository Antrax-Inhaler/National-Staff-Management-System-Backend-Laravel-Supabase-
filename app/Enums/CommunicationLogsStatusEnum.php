<?php

namespace App\Enums;

enum CommunicationLogsStatusEnum: string
{
    case QUEUED = 'Queued';
    case SENT = 'Sent';
    case FAILED = 'Failed';
}