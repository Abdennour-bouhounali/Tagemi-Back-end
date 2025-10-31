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
use App\Http\Controllers\EventController;
use App\Http\Controllers\Content\BlogController;
use App\Http\Controllers\Content\TypeController;
use App\Http\Controllers\Content\MediaController;
use App\Http\Controllers\Content\SponsorController;
use App\Http\Controllers\Content\ActivityController;
use App\Http\Controllers\Content\FutureProjectController;
use App\Http\Controllers\Content\StaticContentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\VolunteerController;
use App\Http\Controllers\UserController;
// routes/api.php

use App\Http\Controllers\ArchivedEventStatisticsController;


// ============================================
// PUBLIC ROUTES (No authentication required)
// ============================================

// Auth routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public appointment booking
Route::post('/appointment/{eventID}', [AppointmentController::class, 'store']);
Route::get('/appointments/available-slots', [AppointmentController::class, 'getAvailableSlots']);
// Maintenance route (protect with admin middleware)
Route::post('/appointments/recalculate-counters', [AppointmentController::class, 'recalculateCounters'])
    ->middleware(['auth:sanctum', 'role:1']); // Only super admin
// Public content
Route::apiResource('activities', ActivityController::class);
Route::get('activities/showByActivitiesType/{id}', [ActivityController::class, 'showByActivitiesType']);

// Public contacts and volunteers
Route::get('/volunteers', [VolunteerController::class, 'index']);
Route::get('/contacts', [ContactController::class, 'index']);

// Public event info
Route::get('/activeEvent', [EventController::class, 'getActiveEvent']);

// Testing endpoint
Route::get('/testing', function() {
    return 'hello world!';
});
Route::post('/contacts', [ContactController::class, 'store']);
Route::post('/volunteers', [VolunteerController::class, 'store']);

// ============================================
// PROTECTED ROUTES (Require authentication)
// ============================================
Route::get('/public/events/{id}', [EventController::class, 'show']);
Route::get('/public/events/{id}/specialties', [EventController::class, 'getSpecialties']);
Route::apiResource('rules', RuleController::class);

Route::middleware('auth:sanctum')->group(function () {
// Auth user info with event and specialties
Route::get('/user', function(Request $request) {
    $user = $request->user();
    
    // Load relationships if user exists
    if ($user) {
        // Load event with its specialties
        $user->load(['event.eventSpecialties.specialty']);
        
        // Optionally, you can add computed properties
        if ($user->event) {
            // Extract unique specialties for easier access
            $specialties = $user->event->eventSpecialties
                ->pluck('specialty')
                ->unique('id')
                ->values();
            
            // Add specialties to user object
            $user->specialties = $specialties;
        }
    }
    
    return $user;
});
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // ============================================
    // EVENT MANAGEMENT
    // ============================================
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/getEvents', [EventController::class, 'index']); // Alias for compatibility
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{id}', [EventController::class, 'show']);
    Route::put('/events/{id}', [EventController::class, 'update']);
    Route::put('/events/{id}/full', [EventController::class, 'updateFull']);
    Route::delete('/events/{id}', [EventController::class, 'destroy']);
    Route::patch('/events/{id}/set-active', [EventController::class, 'toggleActive']);
    Route::patch('/events/{id}/archive', [EventController::class, 'archive']);
    Route::post('/events/{id}/restore', [EventController::class, 'restore']);
    Route::get('/{id}/statistics', [EventController::class, 'statistics']);


    Route::get('/events/{id}/with-stats', [EventController::class, 'showWithStats']);
    
    // Event Specialties
    Route::get('/events/{id}/specialties', [EventController::class, 'getSpecialties']);
    Route::get('/events/{eventId}/specialties', [EventController::class, 'getEventSpecialties']); // Alias
    Route::post('/events/{eventId}/specialties', [EventController::class, 'addSpecialty']);
    Route::put('/events/{eventId}/specialties/{specialtyId}', [EventController::class, 'updateSpecialty']);
    Route::delete('/events/{eventId}/specialties/{specialtyId}', [EventController::class, 'deleteSpecialty']);
    
    // Event Days & Hours
    Route::get('/event-specialties/{id}/days', [EventController::class, 'getDays']);
    Route::get('/days/{id}/hours', [EventController::class, 'getHours']);
    
    // Event Admin Management
    Route::get('/fixed-admins', [EventController::class, 'getFixedAdmins']);
    Route::get('/events/{eventId}/admins', [EventController::class, 'getEventSpecialtiesWithAdmins']);
    Route::get('/events/{eventId}/admins/credentials', [EventController::class, 'getAdminCredentials']);
    Route::post('/events/{eventId}/reset-passwords', [EventController::class, 'resetEventAdminPasswords']);
    Route::delete('/events/{eventId}/admins', [EventController::class, 'deleteEventAdmins']);

    Route::patch('/events/{eventId}/specialties/{eventSpecialtyId}/toggle-saturation', 
        [EventController::class, 'toggleSaturation']);
    
    Route::patch('/events/{eventId}/specialties/batch-saturation', 
        [EventController::class, 'updateSaturationBatch']);
    // Get appointment distribution
    Route::get('/events/{id}/appointment-distribution', [EventController::class, 'getAppointmentDistribution']);
    
    // ============================================
    // APPOINTMENT MANAGEMENT
    // ============================================
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy']);
    Route::post('/appointments/add-multiple', [AppointmentController::class, 'storeMultiple']);
    Route::post('/appointments/addMultiple', [AppointmentController::class, 'addMultiple']); // Alias
    Route::post('/appointment/delete-duplicates', [AppointmentController::class, 'deleteDuplicates']);
    
    // Appointment actions
    Route::post('/appointment/SpecialCase/{id}', [AppointmentController::class, 'SpecialCase']);
    Route::post('/appointment/addComment', [AppointmentController::class, 'addComment']);
    Route::patch('/appointments/{id}/presence', [AppointmentController::class, 'updatePresence']);
    
    // Appointment queries
    Route::get('/appointments/by-event', [AppointmentController::class, 'getByEvent']);
    Route::get('/appointments/search', [AppointmentController::class, 'search']);
    Route::get('/appointments/event-statistics/{eventId}', [AppointmentController::class, 'getEventStatistics']);
    Route::get('/check-slot-availability', [AppointmentController::class, 'checkSlotAvailability']);
    
    // ============================================
    // WAITING LIST MANAGEMENT (Order matters!)
    // ============================================
    
    // Specific routes FIRST
    Route::get('waitinglist/getwaitinglist/{id}', [WaitingListController::class, 'getwaitinglist']);
    Route::get('waitinglist/getwaitinglist', [WaitingListController::class, 'getwaitinglist']);
    Route::get('waitinglist/GetWaitingListBySpeciality/{eventId}/{specialityId}', [WaitingListController::class, 'GetWaitingListBySpeciality']);
    
    // Waiting list actions
    Route::post('waitinglist/Complete/{id}', [WaitingListController::class, 'Completed']);
    Route::post('waitinglist/Absent/{id}', [WaitingListController::class, 'Absent']);
    Route::post('waitinglist/Special/{id}', [WaitingListController::class, 'Special']);
    Route::post('waitinglist/Dilatation/{id}', [WaitingListController::class, 'Dilatation']);
    Route::post('waitinglist/Commingsoon/{id}', [WaitingListController::class, 'Commingsoon']);
    Route::post('waitinglist/FinishedDilatation/{id}', [WaitingListController::class, 'FinishedDilatation']);
    Route::post('waitinglist/deleteAppointmentsAndAdmins', [WaitingListController::class, 'deleteAppointmentsAndAdmins']);
    Route::post('waitinglist/alterSpeciality/{id}', [WaitingListController::class, 'alterSpeciality']);
    
    // Generic resource routes LAST
    Route::apiResource('waitinglist', WaitingListController::class);
    
    // ============================================
    // SPECIALTY MANAGEMENT
    // ============================================
    Route::get('/specialties', [SpecialtyController::class, 'index']); // Alias
    Route::post('/specialty', [SpecialtyController::class, 'store']);
    Route::put('/specialty/{id}', [SpecialtyController::class, 'update']);
    Route::delete('/specialty/{id}', [SpecialtyController::class, 'destroy']);
    
    // ============================================
    // DOCTOR MANAGEMENT
    // ============================================
    Route::get('/doctors', [DoctorController::class, 'index']);
    Route::apiResource('doctor', DoctorController::class);
    
    // ============================================
    // USER & ROLE MANAGEMENT
    // ============================================
    Route::get('/users', [UserController::class, 'getByRole']);
    Route::get('/users/role/{roleId}', [UserController::class, 'getByRoleId']);
    
    Route::apiResource('role', RoleController::class);
    Route::post('role/AssignSpeciality', [RoleController::class, 'AssignSpeciality']);
    Route::post('role/ChangeRole', [RoleController::class, 'ChangeRole']);
    Route::get('/role/getSpecialtiesAdmins', [RoleController::class, 'getSpecialtiesAdmins']);
    
    // ============================================
    // CONTENT MANAGEMENT
    // ============================================
    
   
    Route::post('projects/storeProjectImages', [FutureProjectController::class, 'storeProjectImages']);
    Route::post('projects/edit/{id}', [FutureProjectController::class, 'editProject']);
    Route::delete('projects/delete/{id}', [FutureProjectController::class, 'delete']);
    
    // Media
   
    Route::post('mediaStore', [MediaController::class, 'mediaStore']);
    
    // Types
    Route::post('Updatetype/{id}', [TypeController::class, 'Updatetype']);
    
    // Other content

    Route::post('changeStatisticsLink', [StaticContentController::class, 'changeStatisticsLink']);
    
    // ============================================
    // VOLUNTEERS & CONTACTS
    // ============================================
    Route::delete('/volunteers/{id}', [VolunteerController::class, 'destroy']);
    Route::delete('/contacts/{id}', [ContactController::class, 'destroy']);
    
    // ============================================
    // UTILITY ROUTES
    // ============================================
    Route::get('/getCurrentTime', [UsefulController::class, 'getCurrentTime']);
    Route::get('/getStatistics', [UsefulController::class, 'getStatistics']);
    Route::get('/exportData', [UsefulController::class, 'exportAppointments']);
    Route::post('/startVisitDay', [UsefulController::class, 'startVisitDay']);
    Route::post('/DisplayHideAuth', [UsefulController::class, 'DisplayHideAuth']);
    Route::get('/getstartDay', [UsefulController::class, 'getstartDay']);
});
Route::middleware('auth:sanctum')->get('/getdisplayAuth', [UserController::class, 'getdisplayAuth']);
Route::apiResource('types', TypeController::class);

 // Activities
    Route::post('activities/makeSpecial/{id}', [ActivityController::class, 'makeSpecial']);
    
    // Projects
    Route::apiResource('projects', FutureProjectController::class);
     Route::apiResource('media', MediaController::class);
    Route::apiResource('sponsors', SponsorController::class);
    Route::apiResource('blogs', BlogController::class);
    Route::apiResource('static-contents', StaticContentController::class);

    Route::get('/specialty', [SpecialtyController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    // Archived event statistics routes
    Route::prefix('archived-events/{eventId}')->group(function () {
        Route::get('/specialty-stats', [ArchivedEventStatisticsController::class, 'specialtyStats']);
        Route::get('/status-stats', [ArchivedEventStatisticsController::class, 'statusStats']);
        Route::get('/demographic-stats', [ArchivedEventStatisticsController::class, 'demographicStats']);
        Route::get('/geographic-stats', [ArchivedEventStatisticsController::class, 'geographicStats']);
        Route::get('/patients-list', [ArchivedEventStatisticsController::class, 'patientsList']);
        Route::get('/comprehensive-report', [ArchivedEventStatisticsController::class, 'comprehensiveReport']);
    });
});