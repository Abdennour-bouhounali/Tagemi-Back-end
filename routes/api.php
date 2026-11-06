<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\DoctorAttendanceController;
use App\Http\Controllers\PatientMergeController;
use App\Http\Controllers\QuickRegistrationController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\AdminActionController;
use App\Http\Controllers\UsefulController;
use App\Http\Controllers\SpecialtyController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\WaitingListController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventDraftController;
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
use App\Http\Controllers\ArchivedEventStatisticsController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\PublicTurnController;
use App\Http\Controllers\PatientDataController;

/*
|--------------------------------------------------------------------------
| Access rules
|--------------------------------------------------------------------------
| Every route below declares who may call it:
|   role:<names>               which roles (see User::ROLES)
|   event.access:<type>,<arg>  staff may only touch their own event
|                              (users.event_id); the super admin may touch all.
| Roles: super_admin(1) simple_user(2) special_admin(3) check_admin(4)
|        recipient_admin(5) doctor(6) content_admin(7) patient_manager(8)
*/

// ============================================
// PUBLIC ROUTES (No authentication required)
// ============================================

// Auth
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Public appointment booking
Route::post('/appointment/{eventID}', [AppointmentController::class, 'store'])->whereNumber('eventID')->middleware('throttle:public-forms');
Route::get('/appointments/available-slots', [AppointmentController::class, 'getAvailableSlots']);

// Public event info (no patient data) for the booking form and the waiting-room screen
Route::get('/public/events/{id}', [EventController::class, 'publicShow'])->whereNumber('id');
Route::get('/public/events/{id}/specialties', [EventController::class, 'getSpecialties'])->whereNumber('id');
Route::get('/public/waitinglist', [WaitingListController::class, 'getPublicWaitingList']);
// "See my turn" page (public QR poster): patient number + phone number
Route::post('/public/my-turn', [PublicTurnController::class, 'show'])->middleware('throttle:my-turn');
// Right of access: a patient reads all the data kept about them
Route::post('/public/my-data', [PatientDataController::class, 'show'])->middleware('throttle:my-data');

// Booking rules and specialties shown on the public form
Route::get('/rules', [RuleController::class, 'index']);
Route::get('/rules/{rule}', [RuleController::class, 'show']);
Route::get('/specialty', [SpecialtyController::class, 'index']);

// Patient evaluation form
Route::prefix('evaluations')->group(function () {
    Route::get('/form-data/{eventId}', [EvaluationController::class, 'getFormData']);
    Route::post('/', [EvaluationController::class, 'store'])->middleware('throttle:public-forms');
});

// Public contact / volunteer forms
Route::post('/contacts', [ContactController::class, 'store'])->middleware('throttle:public-forms');
Route::post('/volunteers', [VolunteerController::class, 'store'])->middleware('throttle:public-forms');

// WhatsApp Cloud API webhook (Meta): URL check, then delivery statuses (signed with the app secret)
Route::get('/whatsapp/webhook', [WhatsAppController::class, 'verifyWebhook']);
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'receiveWebhook']);

// Public content (read only)
Route::apiResource('types', TypeController::class)->only(['index', 'show']);
Route::apiResource('projects', FutureProjectController::class)->only(['index', 'show']);
Route::apiResource('media', MediaController::class)->only(['index', 'show']);
Route::apiResource('sponsors', SponsorController::class)->only(['index', 'show']);
Route::apiResource('blogs', BlogController::class)->only(['index', 'show']);
Route::apiResource('static-contents', StaticContentController::class)->only(['index', 'show']);
Route::prefix('activities')->group(function () {
    Route::get('/', [ActivityController::class, 'index']);
    Route::get('/type/{typeId}', [ActivityController::class, 'showByType']);
    Route::get('/{id}', [ActivityController::class, 'show']);
});

// ============================================
// AUTHENTICATED ROUTES
// ============================================
Route::middleware('auth:sanctum')->group(function () {

    // --------------------------------------------
    // Any authenticated user
    // --------------------------------------------

    // Auth user info with event and specialties
    Route::get('/user', function(Request $request) {
        $user = $request->user();
        $user->load(['event.eventSpecialties.specialty']);

        if ($user->event) {
            // Extract unique specialties for easier access
            $user->specialties = $user->event->eventSpecialties
                ->pluck('specialty')
                ->unique('id')
                ->values();
        }

        return $user;
    });
    Route::post('/logout', [AuthController::class, 'logout']);
    // Own Arabic first + family name; the organizer (read-only) may set it too
    Route::put('/user/name', [AuthController::class, 'updateName']);
    Route::get('/getdisplayAuth', [UserController::class, 'getdisplayAuth']);
    Route::get('/getCurrentTime', [UsefulController::class, 'getCurrentTime']);
    Route::get('/getstartDay', [UsefulController::class, 'getstartDay']);
    Route::get('/specialties', [SpecialtyController::class, 'index']);

    // --------------------------------------------
    // Event staff (scoped to their own event)
    // --------------------------------------------
    Route::middleware('role:super_admin,special_admin,check_admin,recipient_admin,doctor,patient_manager,organizer')->group(function () {
        // Event list is filtered to the user's own event in the controller
        Route::get('/events', [EventController::class, 'index']);
        Route::get('/getEvents', [EventController::class, 'index']);
        Route::get('/events/{id}', [EventController::class, 'show'])
            ->whereNumber('id')->middleware('event.access:event,id');
        Route::get('/events/{id}/specialties', [EventController::class, 'getSpecialties'])
            ->whereNumber('id')->middleware('event.access:event,id');
        Route::get('/appointment/patient-appointments/{patientId}/{eventId}', [AppointmentController::class, 'getPatientAppointments'])
            ->middleware('event.access:event,eventId');
        // A patient's history: status changes and manual changes, who and when
        Route::get('/events/{eventId}/patients/{patientId}/log', [AppointmentController::class, 'patientLog'])
            ->whereNumber(['eventId', 'patientId'])->middleware('event.access:event,eventId');
        // Staff screens poll this and reload only when something changed
        Route::get('/events/{eventId}/live-version', [AppointmentController::class, 'liveVersion'])
            ->middleware('event.access:event,eventId');
    });

    // Organizer (مسؤول التنظيم): the live statistics «أثناء الحدث» read these two;
    // since 2026-11-05 no presence desk, no specialty lists (hall screen is public)
    Route::middleware('role:super_admin,special_admin,recipient_admin,organizer')->group(function () {
        Route::get('/appointments/by-event', [AppointmentController::class, 'getByEvent'])
            ->middleware('event.access:event,event_id');
        Route::get('/appointments/event-statistics/{eventId}', [AppointmentController::class, 'getEventStatistics'])
            ->middleware('event.access:event,eventId');
    });

    // Reception (recipient admin) and special-cases admin: presence desk.
    Route::middleware('role:super_admin,special_admin,recipient_admin')->group(function () {
        Route::get('/event-specialties/{id}/days', [EventController::class, 'getDays'])
            ->middleware('event.access:event_specialty,id');
        Route::get('/days/{id}/hours', [EventController::class, 'getHours'])
            ->middleware('event.access:day,id');

        Route::get('/appointments/search', [AppointmentController::class, 'search'])
            ->middleware('event.access:own,event_id');
        Route::get('/check-slot-availability', [AppointmentController::class, 'checkSlotAvailability'])
            ->middleware('event.access:event_specialty,event_specialty_id');

        Route::patch('/appointments/{id}/presence', [AppointmentController::class, 'updatePresence'])
            ->middleware('event.access:appointment,id');
        Route::post('/events/{eventId}/check-in', [AppointmentController::class, 'checkInByNumber'])
            ->middleware('event.access:event,eventId');
        Route::post('/events/{eventId}/check-in/undo', [AppointmentController::class, 'undoCheckIn'])
            ->whereNumber('eventId')->middleware('event.access:event,eventId');
        Route::post('/appointments/{id}/undo-visit', [AppointmentController::class, 'undoVisit'])
            ->whereNumber('id')->middleware('event.access:appointment,id');
        // Same name + phone registered twice: propose and merge into one patient number
        Route::get('/events/{eventId}/duplicates', [PatientMergeController::class, 'eventDuplicates'])
            ->whereNumber('eventId')->middleware('event.access:event,eventId');
        Route::get('/events/{eventId}/patients/{patientId}/duplicates', [PatientMergeController::class, 'duplicates'])
            ->whereNumber(['eventId', 'patientId'])->middleware('event.access:event,eventId');
        Route::post('/events/{eventId}/patients/{patientId}/merge', [PatientMergeController::class, 'merge'])
            ->whereNumber(['eventId', 'patientId'])->middleware('event.access:event,eventId');
        Route::put('/appointments/{id}', [AppointmentController::class, 'update'])
            ->middleware('event.access:appointment,id');
        Route::patch('/appointments/{id}', [AppointmentController::class, 'update'])
            ->middleware('event.access:appointment,id');
        Route::get('/appointments/{id}/available-specialties', [AppointmentController::class, 'getAvailableSpecialties'])
            ->middleware('event.access:appointment,id');
        Route::get('/appointments/{id}/documents/{index}', [DocumentController::class, 'show'])
            ->whereNumber(['id', 'index'])->middleware('event.access:appointment,id');

        // Reminders (WhatsApp) and evaluation links
        Route::post('/appointments/{id}/mark-reminded', [AppointmentController::class, 'markAsReminded'])
            ->middleware('event.access:appointment,id');
        Route::post('/appointments/mark-multiple-reminded', [AppointmentController::class, 'markMultipleAsReminded'])
            ->middleware('event.access:event,event_id');
        Route::get('/appointments/reminder-stats/{eventId}', [AppointmentController::class, 'getReminderStats'])
            ->middleware('event.access:event,eventId');
        Route::post('/appointments/mark-multiple-evaluated', [AppointmentController::class, 'markMultipleAsEvaluated'])
            ->middleware('event.access:event,event_id');
        Route::get('/appointments/evaluated-stats/{eventId}', [AppointmentController::class, 'getEvaluatedStats'])
            ->middleware('event.access:event,eventId');
    });

    // Delete one specialty of a patient: super admin, special admin, and reception
    // (reception: a finished specialty only after «لم يأت بعد» on it)
    Route::middleware('role:super_admin,special_admin,recipient_admin')->group(function () {
        Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy'])
            ->middleware('event.access:appointment,appointment');
    });

    // Special-cases admin
    Route::middleware('role:super_admin,special_admin')->group(function () {
        Route::post('/appointments/{id}/transfer-specialty', [AppointmentController::class, 'transferSpecialty'])
            ->middleware('event.access:appointment,id');
        Route::post('waitinglist/Special/{id}', [WaitingListController::class, 'Special'])
            ->middleware('event.access:appointment,id');
        // Place 3 of the queue, no special-case mark (logged: who, when)
        Route::post('waitinglist/move-to-front/{id}', [WaitingListController::class, 'moveToFront'])
            ->middleware('event.access:appointment,id');
        Route::post('/appointments/add-multiple', [AppointmentController::class, 'storeMultiple'])
            ->middleware('event.access:event,event_id');
        Route::post('/appointments/add-multiple-different', [AppointmentController::class, 'storeMultipleDifferent'])
            ->middleware('event.access:event,event_id');
    });

    // Specialty officer and organizer: register an existing patient in this specialty (past
    // the event's specialty limit only when the super admin switched it on for the event)
    Route::middleware('role:super_admin,check_admin,organizer_acts')->group(function () {
        Route::get('/event-specialties/{id}/register-patient/{patientId}', [AppointmentController::class, 'registerInSpecialtyPreview'])
            ->whereNumber('id')->whereNumber('patientId')->middleware('event.access:event_specialty,id');
        Route::post('/event-specialties/{id}/register-patient', [AppointmentController::class, 'registerInSpecialty'])
            ->whereNumber('id')->middleware('event.access:event_specialty,id');
    });

    // Specialty queue (check admin, organizer), patient manager and doctors
    Route::middleware('role:super_admin,check_admin,doctor,patient_manager,organizer')->group(function () {
        Route::get('/events/{eventId}/stations', [WaitingListController::class, 'stations'])
            ->middleware('event.access:event,eventId');
        Route::get('waitinglist/GetWaitingListBySpeciality/{eventId}/{specialityId}', [WaitingListController::class, 'GetWaitingListBySpeciality'])
            ->middleware('event.access:event,eventId');
        // Doctors of the specialty today, with who is with a patient now
        Route::get('/event-specialties/{id}/doctors-present', [WaitingListController::class, 'doctorsPresent'])
            ->whereNumber('id')->middleware('event.access:event_specialty,id');
        // Dilatation room: run by the officer; doctors and patient manager look only
        Route::get('/event-specialties/{id}/dilatation-room', [WaitingListController::class, 'dilatationRoom'])
            ->middleware('event.access:event_specialty,id');
    });
    Route::middleware('role:super_admin,check_admin,doctor,organizer_acts')->group(function () {
        Route::post('/appointment/addComment', [AppointmentController::class, 'addComment'])
            ->middleware('event.access:appointment,appointment_id');
    });
    // Push down (إنزال): super admin, patient manager and organizer (not the specialty officer since 2026-11-05)
    Route::middleware('role:super_admin,patient_manager,organizer_acts')->group(function () {
        Route::post('waitinglist/Commingsoon/{id}', [WaitingListController::class, 'Commingsoon'])
            ->middleware('event.access:appointment,id');
        Route::post('/waitinglist/push-down', [WaitingListController::class, 'pushDownMany']); // event checked per appointment
    });
    Route::middleware('role:super_admin,check_admin,organizer_acts')->group(function () {
        // دخل (with the chosen doctor; a family enters together), then إجتاز
        Route::post('/waitinglist/enter', [WaitingListController::class, 'enter']); // event checked per appointment
        Route::post('waitinglist/cancel-enter/{id}', [WaitingListController::class, 'cancelEnter'])
            ->middleware('event.access:appointment,id');
        Route::post('waitinglist/absent/{id}', [WaitingListController::class, 'markAbsent'])
            ->middleware('event.access:appointment,id');
        Route::get('waitinglist/relatives/{id}', [WaitingListController::class, 'relatives'])
            ->middleware('event.access:appointment,id');
        Route::post('waitinglist/Complete/{id}', [WaitingListController::class, 'Completed'])
            ->middleware('event.access:appointment,id');
        Route::post('waitinglist/undo-complete/{id}', [WaitingListController::class, 'undoCompleted'])
            ->middleware('event.access:appointment,id');
        Route::post('waitinglist/Dilatation/{id}', [WaitingListController::class, 'Dilatation'])
            ->middleware('event.access:appointment,id');
        Route::post('waitinglist/FinishedDilatation/{id}', [WaitingListController::class, 'FinishedDilatation'])
            ->middleware('event.access:appointment,id');
        // Dilatation room (hidden station of a specialty, run by a specialty officer)
        Route::post('waitinglist/DilatationArrived/{id}', [WaitingListController::class, 'DilatationArrived'])
            ->middleware('event.access:appointment,id');
        Route::get('/appointments/{id}/referral-options', [WaitingListController::class, 'referralOptions'])
            ->middleware('event.access:appointment,id');
        Route::post('/appointments/{id}/refer', [WaitingListController::class, 'refer'])
            ->middleware('event.access:appointment,id');
        // «تبديل التخصص»: to another specialty the patient already booked
        Route::get('/appointments/{id}/switch-options', [WaitingListController::class, 'switchOptions'])
            ->middleware('event.access:appointment,id');
        Route::post('/appointments/{id}/switch-specialty', [WaitingListController::class, 'switchSpecialty'])
            ->middleware('event.access:appointment,id');
    });
    // Visit day dashboards: super admin and the organizer (viewer) of the event
    Route::middleware('role:super_admin')->group(function () {
        Route::get('/events/{id}/doctors-today', [DoctorAttendanceController::class, 'today'])
            ->whereNumber('id')->middleware('event.access:event,id');
    });
    Route::middleware('role:super_admin,organizer')->group(function () {
        Route::get('/events/{id}/appointment-distribution', [EventController::class, 'getAppointmentDistribution'])
            ->whereNumber('id')->middleware('event.access:event,id');
        Route::get('/events/{id}/doctor-visits', [WaitingListController::class, 'doctorVisits'])
            ->whereNumber('id')->middleware('event.access:event,id');
    });
    // Presence desk: add a specialty up to the event's limit (super admin and
    // reception; past the limit only accounts the super admin tagged);
    // type new registrations in a table (reception)
    Route::middleware('role:super_admin,recipient_admin')->group(function () {
        Route::post('/appointments/{id}/add-specialty', [AppointmentController::class, 'addSpecialty'])
            ->whereNumber('id')->middleware('event.access:appointment,id');
    });
    Route::middleware('role:super_admin,special_admin,recipient_admin')->group(function () {
        Route::post('/events/{eventId}/quick-registrations', [QuickRegistrationController::class, 'store'])
            ->whereNumber('eventId')->middleware('event.access:event,eventId');
    });

    // Super admin: change an appointment's time
    Route::middleware('role:super_admin')->group(function () {
        Route::patch('/appointments/{id}/reschedule', [AppointmentController::class, 'reschedule'])
            ->whereNumber('id')->middleware('event.access:appointment,id');
        // Audit trail: every change and login of the staff, who and when
        Route::get('/admin-actions', [AdminActionController::class, 'index']);

        // WhatsApp messages to patients (Cloud API, approved templates)
        Route::get('/whatsapp/status', [WhatsAppController::class, 'status']);
        Route::get('/whatsapp/templates', [WhatsAppController::class, 'templates']);
        Route::post('/events/{eventId}/whatsapp/preview', [WhatsAppController::class, 'preview'])->whereNumber('eventId');
        Route::get('/events/{eventId}/whatsapp/campaigns', [WhatsAppController::class, 'index'])->whereNumber('eventId');
        Route::post('/events/{eventId}/whatsapp/campaigns', [WhatsAppController::class, 'store'])->whereNumber('eventId');
        Route::get('/whatsapp/campaigns/{id}', [WhatsAppController::class, 'show'])->whereNumber('id');
        Route::post('/whatsapp/campaigns/{id}/send-next', [WhatsAppController::class, 'sendNext'])->whereNumber('id');
        Route::post('/whatsapp/campaigns/{id}/retry', [WhatsAppController::class, 'retry'])->whereNumber('id');
        // Manual: typed message, opened one by one in WhatsApp (no API)
        Route::post('/events/{eventId}/whatsapp/manual/preview', [WhatsAppController::class, 'manualPreview'])->whereNumber('eventId');
        Route::post('/events/{eventId}/whatsapp/manual', [WhatsAppController::class, 'storeManual'])->whereNumber('eventId');
        Route::post('/whatsapp/messages/{id}/opened', [WhatsAppController::class, 'markOpened'])->whereNumber('id');
    });
    // Visit day: open a specialty's queue when its doctor is there
    Route::middleware('role:super_admin')->group(function () {
        Route::patch('/event-specialties/{id}/toggle-open', [WaitingListController::class, 'toggleOpen'])
            ->middleware('event.access:event_specialty,id');
        // Add registry doctors to today's day (not planned in the event form): they arrive and open the queue
        Route::post('/event-specialties/{id}/doctors', [DoctorAttendanceController::class, 'add'])
            ->whereNumber('id')->middleware('event.access:event_specialty,id');
        // Doctors' arrival (first one opens the queue), break (back through arrive) and departure (last one closes it)
        Route::post('/event-specialties/{id}/doctors/{doctor}/arrive', [DoctorAttendanceController::class, 'arrive'])
            ->whereNumber(['id', 'doctor']);
        Route::post('/event-specialties/{id}/doctors/{doctor}/break', [DoctorAttendanceController::class, 'takeBreak'])
            ->whereNumber(['id', 'doctor']);
        Route::post('/event-specialties/{id}/doctors/{doctor}/leave', [DoctorAttendanceController::class, 'leave'])
            ->whereNumber(['id', 'doctor']);
        Route::get('/doctors/{doctor}/history', [DoctorAttendanceController::class, 'history'])->whereNumber('doctor');
    });

    // --------------------------------------------
    // Super admin only
    // --------------------------------------------
    Route::middleware('role:super_admin')->group(function () {

        // Event management
        Route::post('/events', [EventController::class, 'store']);
        Route::get('/event-drafts', [EventDraftController::class, 'index']);
        Route::post('/event-drafts', [EventDraftController::class, 'store']);
        Route::get('/event-drafts/{draft}', [EventDraftController::class, 'show'])->whereNumber('draft');
        Route::put('/event-drafts/{draft}', [EventDraftController::class, 'update'])->whereNumber('draft');
        Route::delete('/event-drafts/{draft}', [EventDraftController::class, 'destroy'])->whereNumber('draft');
        Route::post('/events/training', [EventController::class, 'startTraining']);
        Route::post('/events/{id}/training/reset', [EventController::class, 'resetTraining'])->whereNumber('id');
        Route::delete('/events/{id}/training', [EventController::class, 'destroyTraining'])->whereNumber('id');
        Route::put('/events/{id}', [EventController::class, 'update']);
        Route::put('/events/{id}/full', [EventController::class, 'updateFull']);
        Route::get('/events/{id}/edit-data', [EventController::class, 'editData'])->whereNumber('id');
        Route::delete('/events/{id}', [EventController::class, 'destroy']);
        Route::patch('/events/{id}/set-active', [EventController::class, 'toggleActive']);
        Route::patch('/events/{id}/archive', [EventController::class, 'archive']);
        Route::post('/events/{id}/restore', [EventController::class, 'restore']);
        Route::get('/events/{id}/statistics', [EventController::class, 'statistics'])->whereNumber('id');
        Route::get('/{id}/statistics', [EventController::class, 'statistics'])->whereNumber('id'); // legacy path used by Events.jsx
        Route::get('/events/{id}/with-stats', [EventController::class, 'showWithStats']);

        // Event specialties
        Route::post('/events/{eventId}/specialties', [EventController::class, 'addSpecialty']);
        Route::put('/events/{eventId}/specialties/{specialtyId}', [EventController::class, 'updateSpecialty']);
        Route::delete('/events/{eventId}/specialties/{specialtyId}', [EventController::class, 'deleteSpecialty']);
        Route::patch('/events/{eventId}/specialties/batch-saturation', [EventController::class, 'updateSaturationBatch']);
        Route::patch('/events/{eventId}/specialties/{eventSpecialtyId}/toggle-saturation', [EventController::class, 'toggleSaturation']);

        // Event staff accounts
        Route::get('/events/{eventId}/admins/credentials', [EventController::class, 'getAdminCredentials']);
        Route::post('/events/{eventId}/reset-passwords', [EventController::class, 'resetEventAdminPasswords']);
        Route::delete('/events/{eventId}/admins', [EventController::class, 'deleteEventAdmins']);
        Route::post('/events/{id}/assign-to-all-users', [EventController::class, 'assignToAllUsers']);

        // Event reports
        Route::get('/events/{eventId}/report', [EventReportController::class, 'show']);
        Route::post('/events/{eventId}/report', [EventReportController::class, 'createOrUpdate']);
        Route::post('/events/{eventId}/report/approve', [EventReportController::class, 'approve']);
        Route::delete('/events/{eventId}/report', [EventReportController::class, 'destroy']);

        // Deep statistics (matrices) for any event
        Route::get('/events/{eventId}/deep-stats', [ArchivedEventStatisticsController::class, 'deepStats'])->whereNumber('eventId');

        // Archived event statistics
        Route::prefix('archived-events/{eventId}')->group(function () {
            Route::get('/specialty-stats', [ArchivedEventStatisticsController::class, 'specialtyStats']);
            Route::get('/status-stats', [ArchivedEventStatisticsController::class, 'statusStats']);
            Route::get('/demographic-stats', [ArchivedEventStatisticsController::class, 'demographicStats']);
            Route::get('/geographic-stats', [ArchivedEventStatisticsController::class, 'geographicStats']);
            Route::get('/patients-list', [ArchivedEventStatisticsController::class, 'patientsList']);
            Route::get('/comprehensive-report', [ArchivedEventStatisticsController::class, 'comprehensiveReport']);
        });

        // Appointment maintenance
        Route::post('/appointments/recalculate-counters', [AppointmentController::class, 'recalculateCounters']);
        Route::post('/appointment/delete-duplicates', [AppointmentController::class, 'deleteDuplicates']);

        // Test data (works on the fixed test event only)
        Route::post('/test-appointments/create-all', [AppointmentController::class, 'createAllTestAppointments']);
        Route::post('/test-appointments/reset-status', [AppointmentController::class, 'resetTestAppointmentsStatus']);
        Route::get('/test-appointments/count', [AppointmentController::class, 'countTestAppointments']);
        Route::delete('/test-appointments/delete-all', [AppointmentController::class, 'deleteAllTestAppointments']);

        // Evaluations
        Route::get('/evaluations', [EvaluationController::class, 'index']);
        Route::get('/evaluations/{id}', [EvaluationController::class, 'show']);
        Route::patch('/evaluations/{id}/process', [EvaluationController::class, 'markAsProcessed']);
        Route::delete('/evaluations/{id}', [EvaluationController::class, 'destroy']);
        Route::get('events/{eventId}/evaluations/statistics', [EvaluationController::class, 'statistics']);

        // Specialties
        Route::post('/specialty', [SpecialtyController::class, 'store']);
        Route::put('/specialty/{id}', [SpecialtyController::class, 'update']);
        Route::delete('/specialty/{id}', [SpecialtyController::class, 'destroy']);

        // Doctors registry and their login accounts
        Route::get('/doctors', [DoctorController::class, 'index']);
        Route::post('/doctors', [DoctorController::class, 'store']);
        Route::get('/doctors/busy-dates', [DoctorController::class, 'busyDates']);
        Route::get('/doctors/{doctor}', [DoctorController::class, 'show'])->whereNumber('doctor');
        Route::put('/doctors/{doctor}', [DoctorController::class, 'update'])->whereNumber('doctor');
        Route::patch('/doctors/{doctor}/active', [DoctorController::class, 'toggleActive'])->whereNumber('doctor');
        Route::post('/doctors/{doctor}/reset-password', [DoctorController::class, 'resetPassword'])->whereNumber('doctor');
        Route::delete('/doctors/{doctor}', [DoctorController::class, 'destroy'])->whereNumber('doctor');

        // Booking rules
        Route::post('/rules', [RuleController::class, 'store']);
        Route::put('/rules/{rule}', [RuleController::class, 'update']);
        Route::patch('/rules/{rule}', [RuleController::class, 'update']);
        Route::delete('/rules/{rule}', [RuleController::class, 'destroy']);

        // Users & roles
        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::get('/by-role', [UserController::class, 'getByRole']);
            Route::post('/bulk-assign', [UserController::class, 'bulkAssign']);
            Route::get('/role/{roleId}', [UserController::class, 'getByRoleId']);
            Route::get('/event/{eventId}', [UserController::class, 'getByEvent']);
            Route::get('/{id}', [UserController::class, 'show']);
            Route::put('/{id}', [UserController::class, 'update']);
            Route::patch('/{id}/password', [UserController::class, 'changePassword']);
            Route::patch('/{id}/specialty-limit', [UserController::class, 'setSpecialtyLimitOverride']);
            Route::delete('/{id}', [UserController::class, 'destroy']);
            Route::post('/{id}/assign', [UserController::class, 'assignEvent']);
            Route::post('/{id}/unassign', [UserController::class, 'unassignEvent']);
        });
        Route::get('/role', [RoleController::class, 'index']);
        Route::post('role/AssignSpeciality', [RoleController::class, 'AssignSpeciality']);
        Route::post('role/ChangeRole', [RoleController::class, 'ChangeRole']);

        // Visit-day switches and global exports
        Route::get('/getStatistics', [UsefulController::class, 'getStatistics']);
        Route::post('/startVisitDay', [UsefulController::class, 'startVisitDay']);
        Route::post('/DisplayHideAuth', [UsefulController::class, 'DisplayHideAuth']);

        // Contacts & volunteers inbox
        Route::get('/contacts', [ContactController::class, 'index']);
        Route::get('/contacts/statistics', [ContactController::class, 'getStatistics']);
        Route::delete('/contacts/{id}', [ContactController::class, 'destroy']);
        Route::patch('/contacts/{id}/toggle-treated', [ContactController::class, 'toggleTreated']);
        Route::patch('/contacts/{id}/mark-treated', [ContactController::class, 'markAsTreated']);
        Route::patch('/contacts/{id}/mark-untreated', [ContactController::class, 'markAsUntreated']);
        Route::get('/volunteers', [VolunteerController::class, 'index']);
        Route::delete('/volunteers/{id}', [VolunteerController::class, 'destroy']);
    });

    // --------------------------------------------
    // Content management (public site)
    // --------------------------------------------
    Route::middleware('role:super_admin,content_admin')->group(function () {
        Route::apiResource('types', TypeController::class)->except(['index', 'show']);
        Route::apiResource('projects', FutureProjectController::class)->except(['index', 'show']);
        Route::apiResource('media', MediaController::class)->except(['index', 'show']);
        Route::apiResource('sponsors', SponsorController::class)->except(['index', 'show']);
        Route::apiResource('blogs', BlogController::class)->except(['index', 'show']);
        Route::apiResource('static-contents', StaticContentController::class)->except(['index', 'show']);

        Route::post('projects/storeProjectImages', [FutureProjectController::class, 'storeProjectImages']);
        Route::post('projects/edit/{id}', [FutureProjectController::class, 'editProject']);
        Route::delete('projects/delete/{id}', [FutureProjectController::class, 'delete']);
        Route::post('mediaStore', [MediaController::class, 'mediaStore']);
        Route::post('Updatetype/{id}', [TypeController::class, 'Updatetype']);
        Route::post('changeStatisticsLink', [StaticContentController::class, 'changeStatisticsLink']);

        Route::prefix('activities')->group(function () {
            Route::post('/', [ActivityController::class, 'store']);
            Route::put('/{id}', [ActivityController::class, 'update']);
            Route::patch('/{id}', [ActivityController::class, 'update']);
            Route::delete('/{id}', [ActivityController::class, 'destroy']);
            Route::post('/{id}/reorder-media', [ActivityController::class, 'reorderMedia']);
        });
    });
});
