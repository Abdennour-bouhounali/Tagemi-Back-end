<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\UsefulController;
use App\Http\Controllers\SpecialtyController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\WaitingListController;


use App\Http\Controllers\Content\BlogController;
use App\Http\Controllers\Content\TypeController;
use App\Http\Controllers\Content\MediaController;
use App\Http\Controllers\Content\SponsorController;
use App\Http\Controllers\Content\ActivityController;
use App\Http\Controllers\Content\FutureProjectController;
use App\Http\Controllers\Content\StaticContentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\VolunteerController;

Route::get('/volunteers', [VolunteerController::class, 'index']);
Route::post('/volunteers', [VolunteerController::class, 'store']);
Route::delete('/volunteers/{id}', [VolunteerController::class, 'destroy']);

Route::get('/testing',function(){
    return 'hello world!';
});

Route::post('/contacts', [ContactController::class, 'store']);
Route::get('/contacts', [ContactController::class, 'index']);
Route::delete('/contacts/{id}', [ContactController::class, 'destroy']);


Route::get('/user',function(Request $request){
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('activities', ActivityController::class);
Route::apiResource('appointment',AppointmentController::class);
Route::post('appointment/ConfirmPresence/{id}',[AppointmentController::class,'ConfirmPresence']);
Route::post('appointment/ConfirmPresenceDelay/{id}',[AppointmentController::class,'ConfirmPresenceDelay']);
Route::post('appointment/SpecialCase/{id}',[AppointmentController::class,'SpecialCase']);
Route::get('waitinglist/getwaitinglist',[WaitingListController::class,'getwaitinglist']);

Route::apiResource('rules', RuleController::class);
Route::apiResource('projects', FutureProjectController::class);
Route::post('projects/storeProjectImages',[FutureProjectController::class,'storeProjectImages']);
Route::post('projects/edit/{id}',[ FutureProjectController::class,'editProject']);
Route::delete('projects/delete/{id}',[FutureProjectController::class,'delete']);

Route::get('activities/showByActivitiesType/{id}',[ActivityController::class,'showByActivitiesType']);
Route::post('activities/makeSpecial/{id}',[ActivityController::class,'makeSpecial']);

Route::post('changeStatisticsLink',[StaticContentController::class,'changeStatisticsLink']);

Route::apiResource('media', MediaController::class);
Route::post('mediaStore',[MediaController::class,'mediaStore']);
Route::apiResource('types', TypeController::class);
Route::post('Updatetype/{id}',[TypeController::class,'Updatetype']);
Route::apiResource('sponsors', SponsorController::class);
Route::apiResource('blogs', BlogController::class);
Route::apiResource('static-contents', StaticContentController::class);
Route::apiResource('specialty',SpecialtyController::class);

Route::apiResource('role',RoleController::class);
Route::post('role/AssignSpeciality',[RoleController::class,'AssignSpeciality']);
Route::post('role/ChangeRole',[RoleController::class,'ChangeRole']);
Route::get('/role/getSpecialtiesAdmins',[RoleController::class,'getSpecialtiesAdmins']);

Route::get('/getCurrentTime',[UsefulController::class,'getCurrentTime']);
Route::get('/getStatistics',[UsefulController::class,'getStatistics']);
Route::get('/exportData',[UsefulController::class,'exportAppointments']);
Route::post('/startVisitDay',[UsefulController::class,'startVisitDay']);
Route::post('/DisplayHideAuth',[UsefulController::class,'DisplayHideAuth']);
Route::get('/getstartDay',[UsefulController::class,'getstartDay']);
Route::get('/getdisplayAuth',[UsefulController::class,'getdisplayAuth']);



Route::apiResource('doctor',DoctorController::class);
Route::apiResource('waitinglist',WaitingListController::class);
Route::post('waitinglist/Complete/{id}',[WaitingListController::class,'Completed']);
Route::post('waitinglist/Absent/{id}',[WaitingListController::class,'Absent']);
Route::post('waitinglist/deleteAppointmentsAndAdmins',[WaitingListController::class,'deleteAppointmentsAndAdmins']);

Route::get('waitinglist/GetWaitingListBySpeciality/{id}',[WaitingListController::class,'GetWaitingListBySpeciality']);

Route::post('/register',[AuthController::class,'register']);
Route::post('/login',[AuthController::class,'login']);
Route::post('/logout',[AuthController::class,'logout'])->middleware('auth:sanctum');

// CONTENTS ROUTES



