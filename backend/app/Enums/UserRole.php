<?php

namespace App\Enums;

enum UserRole: string
{
    case Editor = 'editor';
    case Approver = 'approver';
    case Admin = 'admin';
}
