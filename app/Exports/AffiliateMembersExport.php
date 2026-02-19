<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;

class AffiliateMembersExport implements FromCollection
{
    private $query;

    public $fileName = 'members.xlsx';

    public function __construct($query)
    {
        $this->query = $query;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->query->get()->map(function ($member) {
            return [
                'Id' => $member->id,
                'First Name' => $member->first_name,
                'Last Name' => $member->last_name,
                'Work Email' => $member->work_email,
                'Work Phone' => $member->work_phone,
                'Employment Status' => $member->employment_status,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Id',
            'First Name',
            'Last Name',
            'Email',
            'Employment Status',
            'Position',
            'Level',
        ];
    }
}
