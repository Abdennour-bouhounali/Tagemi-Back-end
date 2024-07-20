<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Models\Appointment;
use Illuminate\Console\Command;

class DeleteDelayers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:delete-delayers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update status of appointments delayed by more than 2 hours';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $timezone = 'Africa/Algiers';
        $current_time = Carbon::now(new \DateTimeZone($timezone));
        echo $current_time; // Check if the output matches your local time

        
        $delayers = Appointment::where('status', 'Pending')->get();
        
        foreach ($delayers as $delayer) {
            $appointment_time = Carbon::createFromFormat('H:i:s', $delayer->time, $timezone);
            $difference = $appointment_time->diffInSeconds($current_time, false); // false to get negative values
            
            if ($difference > 7200) { // 7200 seconds = 2 hours
                $delayer->status = 'Delay';
                $delayer->save();
            }
        }
    }
}
