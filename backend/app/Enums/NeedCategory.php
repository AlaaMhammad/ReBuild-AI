<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Categories allowed inside damage_assessments.urgent_needs entries.
 */
enum NeedCategory: string
{
    use HasValues;

    case Shelter = 'shelter';
    case Water = 'water';
    case Food = 'food';
    case Medical = 'medical';
    case Hygiene = 'hygiene';
    case Electricity = 'electricity';
    case NonFoodItems = 'non_food_items';
    case DebrisRemoval = 'debris_removal';
    case StructuralRepair = 'structural_repair';
    case Psychosocial = 'psychosocial';
}
