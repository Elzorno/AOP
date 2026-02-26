<?php

namespace App\Enums;

enum RuleSeverity: string
{
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
}
