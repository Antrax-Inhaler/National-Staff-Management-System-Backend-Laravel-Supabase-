<?php

namespace App\Enums;

enum RoleEnum: string
{
    case NATIONAL_OFFICERS = "national_officers";

    case NATIONAL_ADMINISTRATOR = "national_administrator";
    case _EXECUTIVE_COMMITEE = "_executive_commitee";
    case _RESEARCH_COMMITEE = "_research_commitee";

    case AFFILIATE_MEMBER = "affiliate_member";
    case AFFILIATE_OFFICER = "affiliate_officer";

        // ADD Executive Committee Specific Roles
    case PRESIDENT = "president";
    case VICE_PRESIDENT_DEFENSE = "vice_president_defense";
    case VICE_PRESIDENT_PROGRAM = "vice_president_program";
    case SECRETARY = "secretary";
    case TREASURER = "treasurer";

    case REGION_1_DIRECTOR = "region_1_director";
    case REGION_2_DIRECTOR = "region_2_director";
    case REGION_3_DIRECTOR = "region_3_director";
    case REGION_4_DIRECTOR = "region_4_director";
    case REGION_5_DIRECTOR = "region_5_director";
    case REGION_6_DIRECTOR = "region_6_director";
    case REGION_7_DIRECTOR = "region_7_director";
    case AT_LARGE_DIRECTOR_ASSOCIATE = "at_large_director_associate";
    case AT_LARGE_DIRECTOR_PROFESSIONAL = "at_large_director_professional";

    public function description(): string
    {
        return match ($this) {
            self::NATIONAL_OFFICERS => 'Organization Officers',
            self::NATIONAL_ADMINISTRATOR => "Has full administrative access to the system",
            self::_EXECUTIVE_COMMITEE => "Member of the  Executive Committee",
            self::_RESEARCH_COMMITEE => "Member of the  Research Committee",
            self::AFFILIATE_MEMBER => "Affiliate Member with no position",
            self::AFFILIATE_OFFICER => "Affiliate Member with position",


            // ADD descriptions for Executive Committee roles
            self::PRESIDENT => " Executive Committee President",
            self::VICE_PRESIDENT_DEFENSE => " Executive Committee Vice-President of Defense",
            self::VICE_PRESIDENT_PROGRAM => " Executive Committee Vice-President of Program",
            self::SECRETARY => " Executive Committee Secretary",
            self::TREASURER => " Executive Committee Treasurer",
            self::REGION_1_DIRECTOR => " Executive Committee Region 1 Director",
            self::REGION_2_DIRECTOR => " Executive Committee Region 2 Director",
            self::REGION_3_DIRECTOR => " Executive Committee Region 3 Director",
            self::REGION_4_DIRECTOR => " Executive Committee Region 4 Director",
            self::REGION_5_DIRECTOR => " Executive Committee Region 5 Director",
            self::REGION_6_DIRECTOR => " Executive Committee Region 6 Director",
            self::REGION_7_DIRECTOR => " Executive Committee Region 7 Director",
            self::AT_LARGE_DIRECTOR_ASSOCIATE => " Executive Committee At-Large Director for Associate Members",
            self::AT_LARGE_DIRECTOR_PROFESSIONAL => " Executive Committee At-Large Director for Professional Members"
        };
    }

    public function order()
    {
        return match ($this) {
            self::NATIONAL_OFFICERS => null,
            self::NATIONAL_ADMINISTRATOR => 1,
            self::_EXECUTIVE_COMMITEE => 2,
            self::_RESEARCH_COMMITEE => 3,
            self::AFFILIATE_MEMBER => 4,
            self::AFFILIATE_OFFICER => 5,


            // ADD descriptions for Executive Committee roles
            self::PRESIDENT => 6,
            self::VICE_PRESIDENT_DEFENSE => 7,
            self::VICE_PRESIDENT_PROGRAM => 8,
            self::SECRETARY => 9,
            self::TREASURER => 10,
            self::REGION_1_DIRECTOR => 11,
            self::REGION_2_DIRECTOR => 12,
            self::REGION_3_DIRECTOR => 13,
            self::REGION_4_DIRECTOR => 14,
            self::REGION_5_DIRECTOR => 15,
            self::REGION_6_DIRECTOR => 16,
            self::REGION_7_DIRECTOR => 17,
            self::AT_LARGE_DIRECTOR_ASSOCIATE => 18,
            self::AT_LARGE_DIRECTOR_PROFESSIONAL => 19
        };
    }

    public function parent(): ?string
    {
        return match ($this) {
            self::PRESIDENT,
            self::VICE_PRESIDENT_DEFENSE,
            self::VICE_PRESIDENT_PROGRAM,
            self::SECRETARY,
            self::TREASURER,
            self::REGION_1_DIRECTOR,
            self::REGION_2_DIRECTOR,
            self::REGION_3_DIRECTOR,
            self::REGION_4_DIRECTOR,
            self::REGION_5_DIRECTOR,
            self::REGION_6_DIRECTOR,
            self::REGION_7_DIRECTOR,
            self::AT_LARGE_DIRECTOR_ASSOCIATE,
            self::AT_LARGE_DIRECTOR_PROFESSIONAL
            => self::NATIONAL_OFFICERS->value,

            default => null,
        };
    }

    public static function topRoles(): array
    {
        return [
            self::AFFILIATE_MEMBER,
            self::AFFILIATE_OFFICER,
            self::NATIONAL_OFFICERS,
            self::NATIONAL_ADMINISTRATOR
        ];
    }

    public static function nationalRoles(): array
    {
        return [
            self::NATIONAL_OFFICERS->value,
            self::NATIONAL_ADMINISTRATOR->value,
            self::_EXECUTIVE_COMMITEE->value,
            self::_RESEARCH_COMMITEE->value,
            self::PRESIDENT->value,
            self::VICE_PRESIDENT_DEFENSE->value,
            self::VICE_PRESIDENT_PROGRAM->value,
            self::SECRETARY->value,
            self::TREASURER->value,
            self::REGION_1_DIRECTOR->value,
            self::REGION_2_DIRECTOR->value,
            self::REGION_3_DIRECTOR->value,
            self::REGION_4_DIRECTOR->value,
            self::REGION_5_DIRECTOR->value,
            self::REGION_6_DIRECTOR->value,
            self::REGION_7_DIRECTOR->value,
            self::AT_LARGE_DIRECTOR_ASSOCIATE->value,
            self::AT_LARGE_DIRECTOR_PROFESSIONAL->value
        ];
    }

    public static function nationalOfficers(): array
    {
        return [
            self::PRESIDENT->value,
            self::VICE_PRESIDENT_DEFENSE->value,
            self::VICE_PRESIDENT_PROGRAM->value,
            self::SECRETARY->value,
            self::TREASURER->value,
            self::REGION_1_DIRECTOR->value,
            self::REGION_2_DIRECTOR->value,
            self::REGION_3_DIRECTOR->value,
            self::REGION_4_DIRECTOR->value,
            self::REGION_5_DIRECTOR->value,
            self::REGION_6_DIRECTOR->value,
            self::REGION_7_DIRECTOR->value,
            self::AT_LARGE_DIRECTOR_ASSOCIATE->value,
            self::AT_LARGE_DIRECTOR_PROFESSIONAL->value
        ];
    }
}
