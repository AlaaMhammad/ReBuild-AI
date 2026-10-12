<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum OrganizationType: string
{
    use HasValues;

    case Ngo = 'ngo';
    case UnAgency = 'un_agency';
    case Government = 'government';
    case CommunityBased = 'community_based';
    case PrivateContractor = 'private_contractor';
    case Donor = 'donor';
}
