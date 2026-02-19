<?php

namespace App\Enums;

enum CommunicationTypeEnum : string {
    case SMS = 'Sms';
    case EMAIL = 'Email';
}