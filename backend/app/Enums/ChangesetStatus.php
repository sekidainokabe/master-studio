<?php

namespace App\Enums;

enum ChangesetStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Rejected = 'rejected';
    case Applied = 'applied';
}
