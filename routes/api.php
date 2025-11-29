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
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Content\FutureProjectController;
use App\Http\Controllers\Content\StaticContentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\VolunteerController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\EventReportController;
use App\Http\Controllers\EvaluationController;
// routes/api.php

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/events/{eventId}/report', [EventReportController::class, 'show']);
    Route::post('/events/{eventId}/report', [EventReportController::class, 'createOrUpdate']);
    Route::post('/events/{eventId}/report/approve', [EventReportController::class, 'approve']);
    Route::delete('/events/{eventId}/report', [EventReportController::class, 'destroy']);
});
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

Route::middleware('auth:sanctum')->group(function () {
    Route::delete('/contacts/{id}', [ContactController::class, 'destroy']);
    Route::patch('/contacts/{id}/toggle-treated', [ContactController::class, 'toggleTreated']);
    Route::patch('/contacts/{id}/mark-treated', [ContactController::class, 'markAsTreated']);
    Route::patch('/contacts/{id}/mark-untreated', [ContactController::class, 'markAsUntreated']);
    Route::get('/contacts/statistics', [ContactController::class, 'getStatistics']);


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
    Route::post('/events/{id}/assign-to-all-users', [EventController::class, 'assignToAllUsers']);

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
    Route::put('/appointments/{id}', [AppointmentController::class, 'update']);
    Route::patch('/appointments/{id}', [AppointmentController::class, 'update']); // Also support PATCH
    Route::get('/appointment/patient-appointments/{patientId}/{eventId}', [AppointmentController::class, 'getPatientAppointments']);

    
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
    Route::post('/waitinglist/alterSpeciality/{id}', [WaitingListController::class, 'alterSpeciality']);
    Route::get('waitinglist/version/{eventId}/{specialityId}', [WaitingListController::class, 'getWaitingListVersion']);
    

    
    // Generic resource routes LAST
    Route::apiResource('waitinglist', WaitingListController::class);
    
    // ============================================
    // SPECIALTY MANAGEMENT
    // ============================================
    Route::get('/specialties', [SpecialtyController::class, 'index']); // Alias
    Route::post('/specialty', [SpecialtyController::class, 'store']);
    Route::put('/specialty/{id}', [SpecialtyController::class, 'update']);
    Route::delete('/specialty/{id}', [SpecialtyController::class, 'destroy']);

     // Specialty transfer routes
    Route::get('/appointments/{id}/available-specialties', [AppointmentController::class, 'getAvailableSpecialties']);
    Route::post('/appointments/{id}/transfer-specialty', [AppointmentController::class, 'transferSpecialty']);
    
    // ============================================
    // DOCTOR MANAGEMENT
    // ============================================
    Route::get('/doctors', [DoctorController::class, 'index']);
    Route::apiResource('doctor', DoctorController::class);
    
    // ============================================
    // USER & ROLE MANAGEMENT
    // ============================================
    Route::get('/users', [UserController::class, 'index']); // List all users
    Route::post('/users/{id}/assign', [UserController::class, 'assignEvent']); // Assign user to event
    Route::get('/users/by-role', [UserController::class, 'getByRole']); // Renamed to avoid conflict if needed, or keep as is if query param is used
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
    // ============================================
    // USERS ROUTES
    // ==========================================

    Route::prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index']);                    // List all users
    Route::get('/by-role', [UserController::class, 'getByRole']);         // Get by role (query param)
    Route::get('/role/{roleId}', [UserController::class, 'getByRoleId']); // Get by role (path param)
    Route::get('/event/{eventId}', [UserController::class, 'getByEvent']); // Get by event
    Route::get('/{id}', [UserController::class, 'show']);                 // Get single user
    Route::put('/{id}', [UserController::class, 'update']);               // Update user
    Route::delete('/{id}', [UserController::class, 'destroy']);           // Delete user
    Route::post('/{id}/assign', [UserController::class, 'assignEvent']);  // Assign event & role
    Route::post('/{id}/unassign', [UserController::class, 'unassignEvent']); // Unassign from event
});
});
Route::apiResource('rules', RuleController::class);
Route::middleware('auth:sanctum')->get('/getdisplayAuth', [UserController::class, 'getdisplayAuth']);
Route::apiResource('types', TypeController::class);

 // Activities    
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

// Event Reports Routes
Route::prefix('events/{eventId}/reports')->group(function () {
    Route::get('/', [EventReportController::class, 'getReport']);
    Route::post('/', [EventReportController::class, 'storeOrUpdate']);
    Route::delete('/', [EventReportController::class, 'destroy']);
});

// Get all reports
Route::get('/reports', [EventReportController::class, 'getAllReports']);

// Activities Routes
Route::prefix('activities')->group(function () {
    // Public routes
    Route::get('/', [ActivityController::class, 'index']);
    Route::get('/type/{typeId}', [ActivityController::class, 'showByType']);
    Route::get('/{id}', [ActivityController::class, 'show']);

    
    // Protected routes for admin (require authentication)
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/', [ActivityController::class, 'store']);
        Route::put('/{id}', [ActivityController::class, 'update']);
        Route::patch('/{id}', [ActivityController::class, 'update']); // Also support PATCH
        Route::delete('/{id}', [ActivityController::class, 'destroy']);
        Route::post('/{id}/reorder-media', [ActivityController::class, 'reorderMedia']);
    });

});
Route::get('/public/waitinglist', [WaitingListController::class, 'getPublicWaitingList']);


Route::post('/appointments/add-multiple-different', [AppointmentController::class, 'storeMultipleDifferent'])
    ->middleware('auth:sanctum');


Route::middleware('auth:sanctum')->group(function () {
    // Create test appointments for ALL specialties in event (15 × specialties count)
    Route::post('/test-appointments/create-all', [AppointmentController::class, 'createAllTestAppointments']);
    
    // Reset all test appointments status to Pending
    Route::post('/test-appointments/reset-status', [AppointmentController::class, 'resetTestAppointmentsStatus']);
    
    // Count test appointments
    Route::get('/test-appointments/count', [AppointmentController::class, 'countTestAppointments']);
    
    // Delete all test appointments
    Route::delete('/test-appointments/delete-all', [AppointmentController::class, 'deleteAllTestAppointments']);
});

use App\Http\Controllers\WhatsAppBotController;

// WhatsApp Bot Routes
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Execute WhatsApp bot
    Route::post('/whatsapp-bot/execute', [WhatsAppBotController::class, 'executeBot']);
    
    // Get bot status and logs
    Route::get('/whatsapp-bot/status', [WhatsAppBotController::class, 'getStatus']);
    
    // Test configuration
    Route::get('/whatsapp-bot/test-config', [WhatsAppBotController::class, 'testConfig']);
    
});


// في ملف routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    // Mark appointment as reminded
    Route::post('/appointments/{id}/mark-reminded', [AppointmentController::class, 'markAsReminded']);
    
    // Mark multiple appointments as reminded
    Route::post('/appointments/mark-multiple-reminded', [AppointmentController::class, 'markMultipleAsReminded']);
    
    // Get reminder statistics
    Route::get('/appointments/reminder-stats/{eventId}', [AppointmentController::class, 'getReminderStats']);
});


// Dans routes/api.php

// Public routes (no authentication required)
Route::prefix('evaluations')->group(function () {
    // Get form data for specific event
    Route::get('/form-data/{eventId}', [EvaluationController::class, 'getFormData']);
    
    // Submit evaluation
    Route::post('/', [EvaluationController::class, 'store']);
});

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->prefix('evaluations')->group(function () {
    // Admin only routes
   
        // List all evaluations
        Route::get('/', [EvaluationController::class, 'index']);
        
        // Get single evaluation
        Route::get('/{id}', [EvaluationController::class, 'show']);
        
        // Mark as processed
        Route::patch('/{id}/process', [EvaluationController::class, 'markAsProcessed']);
        
        // Delete evaluation
        Route::delete('/{id}', [EvaluationController::class, 'destroy']);
        
        // Get statistics for event
    
});
Route::get('events/{eventId}/evaluations/statistics', [EvaluationController::class, 'statistics']);
