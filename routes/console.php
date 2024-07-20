<?php

use App\Models\Parameter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();


 
// Run once per week on Monday at 1 PM...
Schedule::call(function () {
    
})->weekly()->mondays()->at('13:00');
 

Schedule::command('app:delete-delayers')->everyMinute()->when(function () {
    $isDay = Parameter::find(1)->is_day_visits;
    

    return $isDay;
});