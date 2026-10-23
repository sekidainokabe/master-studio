<?php

namespace App\Enums;

enum ColumnType: string
{
    case Int = 'int';
    case Float = 'float';
    case String = 'string';
    case Bool = 'bool';
    case Enum = 'enum';
    case Ref = 'ref';
}
