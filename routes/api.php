<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\SpecialtyController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\UsefulController;
use App\Http\Controllers\WaitingListController;

Route::get('/user',function(Request $request){
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('appointment',AppointmentController::class);
Route::post('appointment/ConfirmPresence/{id}',[AppointmentController::class,'ConfirmPresence']);
Route::post('appointment/ConfirmPresenceDelay/{id}',[AppointmentController::class,'ConfirmPresenceDelay']);
Route::post('appointment/SpecialCase/{id}',[AppointmentController::class,'SpecialCase']);




Route::apiResource('specialty',SpecialtyController::class);

Route::apiResource('role',RoleController::class);
Route::post('role/AssignSpeciality',[RoleController::class,'AssignSpeciality']);
Route::post('role/ChangeRole',[RoleController::class,'ChangeRole']);
Route::get('/role/getSpecialtiesAdmins',[RoleController::class,'getSpecialtiesAdmins']);

Route::get('/getCurrentTime',[UsefulController::class,'getCurrentTime']);
Route::get('/getStatistics',[UsefulController::class,'getStatistics']);
Route::get('/exportData',[UsefulController::class,'exportAppointments']);

Route::apiResource('doctor',DoctorController::class);
Route::apiResource('waitinglist',WaitingListController::class);
Route::post('waitinglist/Complete/{id}',[WaitingListController::class,'Completed']);
Route::post('waitinglist/Absent/{id}',[WaitingListController::class,'Absent']);
Route::post('waitinglist/deleteAppointmentsAndAdmins',[WaitingListController::class,'deleteAppointmentsAndAdmins']);

Route::get('waitinglist/GetWaitingListBySpeciality/{id}',[WaitingListController::class,'GetWaitingListBySpeciality']);

Route::post('/register',[AuthController::class,'register']);
Route::post('/login',[AuthController::class,'login']);
Route::post('/logout',[AuthController::class,'logout'])->middleware('auth:sanctum');
