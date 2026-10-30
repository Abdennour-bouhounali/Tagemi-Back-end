<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Doctors registry (super admin only, see routes/api.php). Each doctor gets a
 * login account (role doctor) when created; its password is shown once, at
 * creation or after a reset, and only its hash is stored.
 */
class DoctorController extends Controller
{
    // No 0/O, 1/l/I: the password is read out or typed from a message
    private const PASSWORD_ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function index(Request $request)
    {
        $filters = $request->validate([
            'specialty_id' => 'nullable|integer',
            'active' => 'nullable|boolean',
            'q' => 'nullable|string|max:100',
        ]);

        $doctors = Doctor::with(['specialty:id,name', 'user:id,email'])
            ->when(isset($filters['specialty_id']), fn ($q) => $q->where('specialty_id', $filters['specialty_id']))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', (bool) $filters['active']))
            ->when(!empty($filters['q']), function ($q) use ($filters) {
                $term = '%' . trim($filters['q']) . '%';
                $q->where(fn ($w) => $w->where('first_name', 'like', $term)
                    ->orWhere('family_name', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->orderBy('family_name')->orderBy('first_name')
            ->get();

        return ['doctors' => $doctors];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        [$doctor, $password] = DB::transaction(function () use ($data) {
            $doctor = Doctor::create($data + ['is_active' => true]);

            $password = $this->generatePassword();
            $user = User::create([
                'name' => $doctor->full_name,
                'email' => $this->loginEmail($doctor),
                'password' => $password,
                'phone' => $doctor->phone,
            ]);
            $user->forceFill(['role_id' => User::ROLE_DOCTOR])->save();

            $doctor->user()->associate($user)->save();

            return [$doctor, $password];
        });

        return response()->json([
            'message' => 'أُضيف الطبيب وأُنشئ حسابه',
            'doctor' => $doctor->load(['specialty:id,name', 'user:id,email']),
            'credentials' => ['email' => $doctor->user->email, 'password' => $password],
        ], 201);
    }

    /**
     * Dates each doctor already works in other (non-archived) events: the
     * event form warns, it does not refuse.
     */
    public function busyDates(Request $request)
    {
        $exclude = $request->integer('exclude_event_id') ?: null;

        $busy = DB::table('doctor_intervals as di')
            ->join('days as d', 'd.id', '=', 'di.day_id')
            ->join('event_specialties as es', 'es.id', '=', 'd.event_specialty_id')
            ->join('events as e', 'e.id', '=', 'es.event_id')
            ->where('e.is_archived', false)
            ->when($exclude, fn ($q) => $q->where('e.id', '!=', $exclude))
            ->select('di.doctor_id', 'd.day_date', 'e.id as event_id', 'e.name as event_name')
            ->distinct()
            ->get();

        return ['busy' => $busy];
    }

    public function show(Doctor $doctor)
    {
        return ['doctor' => $doctor->load(['specialty:id,name', 'user:id,email'])];
    }

    public function update(Request $request, Doctor $doctor)
    {
        $data = $this->validated($request, $doctor);

        if ((int) $data['specialty_id'] !== (int) $doctor->specialty_id && $doctor->intervals()->exists()) {
            return response()->json(['message' => 'لا يمكن تغيير تخصص طبيب مبرمج في حدث. احذفه من الحدث أولاً.'], 409);
        }

        DB::transaction(function () use ($doctor, $data) {
            $doctor->update($data);
            $doctor->user?->update(['name' => $doctor->full_name, 'phone' => $doctor->phone]);
        });

        return [
            'message' => 'حُدّثت بيانات الطبيب',
            'doctor' => $doctor->load(['specialty:id,name', 'user:id,email']),
        ];
    }

    /** A deactivated doctor cannot log in and is not offered in event forms. */
    public function toggleActive(Doctor $doctor)
    {
        $doctor->update(['is_active' => !$doctor->is_active]);

        if (!$doctor->is_active) {
            $doctor->user?->tokens()->delete();
        }

        return [
            'message' => $doctor->is_active ? 'فُعّل الطبيب' : 'أُوقف الطبيب وحسابه',
            'doctor' => $doctor->load(['specialty:id,name', 'user:id,email']),
        ];
    }

    /** New password, shown once; open sessions are signed out. */
    public function resetPassword(Doctor $doctor)
    {
        $password = $this->generatePassword();

        $user = DB::transaction(function () use ($doctor, $password) {
            $user = $doctor->user;
            if (!$user) {
                // Account deleted from the users page: create it again
                $user = User::create([
                    'name' => $doctor->full_name,
                    'email' => $this->loginEmail($doctor),
                    'password' => $password,
                    'phone' => $doctor->phone,
                ]);
                $user->forceFill(['role_id' => User::ROLE_DOCTOR])->save();
                $doctor->user()->associate($user)->save();
            } else {
                $user->update(['password' => $password]);
                $user->tokens()->delete();
            }

            return $user;
        });

        return [
            'message' => 'أُنشئت كلمة مرور جديدة',
            'credentials' => ['email' => $user->email, 'password' => $password],
        ];
    }

    /** Only a doctor never placed in an event can be deleted; otherwise deactivate. */
    public function destroy(Doctor $doctor)
    {
        if ($doctor->intervals()->exists() || $doctor->eventSpecialties()->exists() || $doctor->appointments()->exists()) {
            return response()->json(['message' => 'هذا الطبيب مبرمج في حدث، لا يمكن حذفه. أوقفه بدلاً من ذلك.'], 409);
        }

        DB::transaction(function () use ($doctor) {
            $user = $doctor->user;
            $doctor->delete();
            if ($user) {
                $user->tokens()->delete();
                $user->delete();
            }
        });

        return ['message' => 'حُذف الطبيب وحسابه'];
    }

    private function validated(Request $request, ?Doctor $doctor = null): array
    {
        // The phone is optional: spaces and dashes are stripped, empty means none
        $request->merge(['phone' => preg_replace('/\D/', '', (string) $request->input('phone')) ?: null]);

        return $request->validate([
            'first_name' => 'required|string|max:100',
            'family_name' => 'required|string|max:100',
            'sex' => ['required', Rule::in(Doctor::SEXES)],
            'phone' => ['nullable', 'regex:/^0[2-7]\d{7,8}$/', Rule::unique('doctors', 'phone')->ignore($doctor?->id)],
            'wilaya_code' => ['required', 'regex:/^(0[1-9]|[1-4]\d|5[0-8])$/'],
            'specialty_id' => 'required|exists:specialties,id',
            'sector' => ['required', Rule::in(Doctor::SECTORS)],
        ], [
            'first_name.*' => 'اكتب الاسم',
            'family_name.*' => 'اكتب اللقب',
            'sex.*' => 'اختر الجنس',
            'phone.regex' => 'رقم الهاتف غير صحيح (مثال: 0555123456)',
            'phone.unique' => 'رقم الهاتف مسجل لطبيب آخر',
            'wilaya_code.*' => 'اختر الولاية',
            'specialty_id.*' => 'اختر التخصص',
            'sector.*' => 'اختر مكان العمل',
        ]);
    }

    /** Login name only (not a mailbox): built from the id since names are in Arabic. */
    private function loginEmail(Doctor $doctor): string
    {
        $email = "doctor.{$doctor->id}@tagemi.local";
        for ($n = 2; User::where('email', $email)->exists(); $n++) {
            $email = "doctor.{$doctor->id}.{$n}@tagemi.local";
        }

        return $email;
    }

    private function generatePassword(int $length = 10): string
    {
        $max = strlen(self::PASSWORD_ALPHABET) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= self::PASSWORD_ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
