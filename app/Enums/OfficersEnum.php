<?php

namespace App\Enums;

enum OfficersEnum: string
{
    case PRESIDENT = "President";
    case VICE_PRESIDENT = "Vice President";
    case SECRETARY = "Secretary";
    case TREASURER = "Treasurer";
    case GRIEVANCE_CHAIR = "Grievance Chair";
    case BARGAINING_CHAIR = "Bargaining Chair";
    case COMMUNICATIONS_COMMITTEE_CHAIR = "Communications Commitee Chair";
    case HEALTH_INSURANCE_COMMITTEE_CHAIR_STAFF_REPRESENTATIVE = "Health Insurance Committee Chair/Staff Representative";
    case FOUR_OH_ONE_K_COMMITTEE_CHAIR_STAFF_REPRESENTATIVE = "401k Committee Chair/Staff Representative";
    case PENSION_COMMITTEE_CHAIR_STAFF_REPRESENTATIVE = "Pension Committee Chair/Staff Representative";
    case MEMBERSHIP_CHAIR = "Membership Chair";

    public function order(): string
    {
        return match ($this) {
            self::PRESIDENT => 1,
            self::VICE_PRESIDENT => 2,
            self::SECRETARY => 3,
            self::TREASURER => 4,
            self::GRIEVANCE_CHAIR => 5,
            self::BARGAINING_CHAIR => 6,
            self::COMMUNICATIONS_COMMITTEE_CHAIR => 7,
            self::HEALTH_INSURANCE_COMMITTEE_CHAIR_STAFF_REPRESENTATIVE => 8,
            self::FOUR_OH_ONE_K_COMMITTEE_CHAIR_STAFF_REPRESENTATIVE => 9,
            self::PENSION_COMMITTEE_CHAIR_STAFF_REPRESENTATIVE => 10,
            self::MEMBERSHIP_CHAIR => 11,
        };
    }
}
