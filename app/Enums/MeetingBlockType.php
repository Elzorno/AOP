<?php

namespace App\Enums;

enum MeetingBlockType: string
{
    case LECTURE = 'LECTURE';
    case LAB = 'LAB';
    case OTHER = 'OTHER';
}
