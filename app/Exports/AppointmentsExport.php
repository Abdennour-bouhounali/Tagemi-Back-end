<?php

namespace App\Exports;

use App\Models\Appointment;
use Maatwebsite\Excel\Concerns\FromCollection;

class AppointmentsExport implements FromCollection
{
    /**
    * @return \Illuminate\Support\Collection
    */

    public function collection()
    {
         $appointments = Appointment::with(['user','specialty'])->get();
                 // Custom header row
        $headerRow = [
            'Patient Id',
            'Name',
            'Registred User',
            'User Phone',
            'Speciality',
            'Time',
            'Position',
            'Status',
        ];

        // Map the data to include only the desired columns
        $mappedData = $appointments->map(function ($appointment) {
            return [
            'Patient Id'=>$appointment->patinet_id,
            'Name'=>$appointment->name,
            'Registred User'=>$appointment->user->name,
            'User Phone'=>$appointment->user->phone,
            'Speciality'=>$appointment->specialty->name,
            'Time'=>$appointment->time,
            'Position'=>$appointment->position,
            'Status'=>$appointment->status,
            ];
        });

        // Prepend the custom header row to the mapped data
        $exportData = collect([$headerRow])->concat($mappedData);
        
        
        return $exportData;
    }
}
