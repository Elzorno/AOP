<?php

namespace App\Enums;

enum SectionModality: string
{
    case IN_PERSON = 'IN_PERSON';
    case HYBRID = 'HYBRID';
    case ONLINE = 'ONLINE';
}
