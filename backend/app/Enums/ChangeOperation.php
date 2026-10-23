<?php

namespace App\Enums;

enum ChangeOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
