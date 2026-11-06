<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change made in the staff space (or a staff login attempt). See RecordAdminAction. */
class AdminAction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['subject' => 'array', 'input' => 'array'];

    /** Readable name of each action (Controller@method); others show their path. */
    public const LABELS = [
        'AuthController@login' => 'تسجيل الدخول',
        'AuthController@logout' => 'تسجيل الخروج',
        // Presence desk / appointments
        'AppointmentController@checkInByNumber' => 'سجّل حضور زائر',
        'AppointmentController@updatePresence' => 'سجّل حضور زائر',
        'AppointmentController@undoCheckIn' => 'ألغى حضور زائر',
        'AppointmentController@update' => 'عدّل بيانات موعد',
        'AppointmentController@destroy' => 'حذف موعداً',
        'AppointmentController@reschedule' => 'غيّر وقت موعد',
        'AppointmentController@addSpecialty' => 'أضاف تخصصاً لزائر',
        'AppointmentController@registerInSpecialty' => 'سجّل زائراً في تخصصه (مسؤول التخصص)',
        'AppointmentController@transferSpecialty' => 'نقل موعداً إلى تخصص آخر',
        'AppointmentController@storeMultiple' => 'أضاف مواعيد',
        'AppointmentController@storeMultipleDifferent' => 'أضاف مواعيد',
        'AppointmentController@store' => 'حجز موعداً',
        'AppointmentController@addComment' => 'أضاف تعليقاً',
        'AppointmentController@deleteDuplicates' => 'حذف المواعيد المكررة',
        'AppointmentController@recalculateCounters' => 'أعاد حساب العدادات',
        'AppointmentController@markAsReminded' => 'أرسل تذكيراً',
        'AppointmentController@markMultipleAsReminded' => 'أرسل تذكيراً',
        'AppointmentController@markMultipleAsEvaluated' => 'أرسل رابط التقييم',
        'AppointmentController@createAllTestAppointments' => 'أنشأ مواعيد تجريبية',
        'AppointmentController@resetTestAppointmentsStatus' => 'صفّر المواعيد التجريبية',
        'AppointmentController@deleteAllTestAppointments' => 'حذف المواعيد التجريبية',
        'PatientMergeController@merge' => 'دمج تسجيلاً مكرراً',
        'QuickRegistrationController@store' => 'أضاف تسجيلات من مكتب الحضور',
        'WhatsAppController@preview' => 'عاين رسالة واتساب',
        'WhatsAppController@store' => 'أنشأ إرسال واتساب',
        'WhatsAppController@sendNext' => 'أرسل دفعة رسائل واتساب',
        'WhatsAppController@retry' => 'أعاد إرسال رسائل واتساب الفاشلة',
        'WhatsAppController@manualPreview' => 'عاين رسالة واتساب يدوية',
        'WhatsAppController@storeManual' => 'أنشأ قائمة رسائل واتساب يدوية',
        'WhatsAppController@markOpened' => 'فتح رسالة واتساب يدوية لزائر',
        'UserController@setSpecialtyLimitOverride' => 'غيّر صلاحية تجاوز عدد التخصصات',
        // Queues
        'WaitingListController@Special' => 'سجّل حالة خاصة',
        'WaitingListController@moveToFront' => 'قدّم زائراً إلى المركز 3',
        'WaitingListController@pushDownMany' => 'أنزل زوار في الطابور',
        'WaitingListController@enter' => 'أدخل زائراً إلى الطبيب',
        'WaitingListController@cancelEnter' => 'ألغى دخول زائر',
        'WaitingListController@Completed' => 'سجّل اجتياز زائر',
        'WaitingListController@switchSpecialty' => 'بدّل تخصص زائر',
        'WaitingListController@Commingsoon' => 'نادى زائراً',
        'WaitingListController@Dilatation' => 'أرسل زائراً إلى التوسيع',
        'WaitingListController@DilatationArrived' => 'استقبل زائراً في التوسيع',
        'WaitingListController@FinishedDilatation' => 'أنهى توسيع زائر',
        'WaitingListController@refer' => 'وجّه زائراً إلى تخصص',
        'WaitingListController@toggleOpen' => 'فتح/أغلق تخصصاً',
        'DoctorAttendanceController@add' => 'أضاف أطباء إلى تخصص وفتحه',
        'DoctorAttendanceController@arrive' => 'سجّل وصول طبيب',
        'DoctorAttendanceController@takeBreak' => 'سجّل استراحة طبيب',
        'DoctorAttendanceController@leave' => 'سجّل مغادرة طبيب',
        // Events
        'EventController@store' => 'أنشأ حدثاً',
        'EventController@update' => 'عدّل حدثاً',
        'EventController@updateFull' => 'عدّل حدثاً',
        'EventController@destroy' => 'حذف حدثاً',
        'EventController@archive' => 'أرشف حدثاً',
        'EventController@restore' => 'استرجع حدثاً',
        'EventController@toggleActive' => 'فعّل/أوقف حدثاً',
        'EventController@addSpecialty' => 'أضاف تخصصاً للحدث',
        'EventController@updateSpecialty' => 'عدّل تخصصاً في الحدث',
        'EventController@deleteSpecialty' => 'حذف تخصصاً من الحدث',
        'EventController@toggleSaturation' => 'غيّر امتلاء تخصص',
        'EventController@updateSaturationBatch' => 'غيّر امتلاء التخصصات',
        'EventController@deleteEventAdmins' => 'حذف مشرفي الحدث',
        'EventController@resetEventAdminPasswords' => 'أعاد كلمات مرور مشرفي الحدث',
        'EventController@assignToAllUsers' => 'ربط كل المستخدمين بالحدث',
        'EventController@startTraining' => 'بدأ حدثاً تدريبياً',
        'EventController@resetTraining' => 'صفّر الحدث التدريبي',
        'EventController@destroyTraining' => 'حذف الحدث التدريبي',
        'EventReportController@createOrUpdate' => 'كتب تقرير الحدث',
        'EventReportController@approve' => 'اعتمد تقرير الحدث',
        'EventReportController@destroy' => 'حذف تقرير الحدث',
        'UsefulController@startVisitDay' => 'بدأ يوم الزيارة',
        // Users, doctors, roles
        'UserController@update' => 'عدّل مستخدماً',
        'UserController@destroy' => 'حذف مستخدماً',
        'UserController@assignEvent' => 'ربط مستخدماً بحدث',
        'UserController@unassignEvent' => 'فكّ ربط مستخدم بحدث',
        'UserController@bulkAssign' => 'ربط مستخدمين بحدث',
        'RoleController@ChangeRole' => 'غيّر دور مستخدم',
        'RoleController@AssignSpeciality' => 'ربط مستخدماً بتخصص',
        'DoctorController@store' => 'أضاف طبيباً',
        'DoctorController@update' => 'عدّل طبيباً',
        'DoctorController@destroy' => 'حذف طبيباً',
        'DoctorController@toggleActive' => 'فعّل/أوقف حساب طبيب',
        'DoctorController@resetPassword' => 'أعاد كلمة مرور طبيب',
        'SpecialtyController@store' => 'أضاف تخصصاً',
        'SpecialtyController@update' => 'عدّل تخصصاً',
        'SpecialtyController@destroy' => 'حذف تخصصاً',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->action] ?? ($this->method . ' ' . $this->path);
    }
}
