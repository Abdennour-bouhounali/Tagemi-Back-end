<?php

namespace App\Models;

use App\Services\QueueService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * A doctor's attendance on one day of an event specialty: arrived_at is the
 * first arrival, left_at the last departure (empty while present or on a
 * break). Each in/out of the day is kept in periods. A doctor on a break
 * (on_break_since) still belongs to the specialty, as queue and capacity
 * rules see it, but takes no patients until back.
 */
class DoctorAttendance extends Model
{
    protected $fillable = [
        'doctor_id',
        'day_id',
        'arrived_at',
        'left_at',
        'on_break_since',
        'note',
        'recorded_by',
    ];

    protected $casts = [
        'arrived_at' => 'datetime',
        'left_at' => 'datetime',
        'on_break_since' => 'datetime',
    ];

    public function isPresent(): bool
    {
        return $this->arrived_at !== null && $this->left_at === null;
    }

    public function isOnBreak(): bool
    {
        return $this->isPresent() && $this->on_break_since !== null;
    }

    /** Here and able to see patients. */
    public function isWorking(): bool
    {
        return $this->isPresent() && $this->on_break_since === null;
    }

    /** Comes in: for the first time today, back after leaving, or back from a break. */
    public function recordArrival(): void
    {
        $this->arrived_at ??= now();
        $this->left_at = null;
        $this->on_break_since = null;
        $this->recorded_by = Auth::id();
        $this->save();
        $this->periods()->create(['arrived_at' => now()]);
    }

    public function recordBreak(): void
    {
        $this->on_break_since = now();
        $this->recorded_by = Auth::id();
        $this->save();
        $this->periods()->whereNull('left_at')->update(['left_at' => now(), 'ended_by_break' => true]);
    }

    /** Leaves for now; from a break that never ended, they left when it began. */
    public function recordDeparture(): void
    {
        if ($this->isOnBreak()) {
            $this->periods()->latest('id')->first()?->update(['ended_by_break' => false]);
        }
        $this->left_at = $this->on_break_since ?? now();
        $this->on_break_since = null;
        $this->recorded_by = Auth::id();
        $this->save();
        $this->periods()->whereNull('left_at')->update(['left_at' => now()]);
    }

    /** When the current stay began (the return after a break); null while out or on a break. */
    public function presentSince(): ?CarbonInterface
    {
        if (!$this->isWorking()) {
            return null;
        }

        return $this->periods->sortBy('id')->last()?->arrived_at ?? $this->arrived_at;
    }

    /** The day's stays in order, as clock times in the event's timezone. */
    public function periodClocks(): array
    {
        $clock = fn (?CarbonInterface $time) => $time?->copy()->setTimezone(QueueService::TIMEZONE)->format('H:i');

        return $this->periods->sortBy('id')
            ->map(fn ($p) => ['arrived_at' => $clock($p->arrived_at), 'left_at' => $clock($p->left_at), 'break' => $p->ended_by_break])
            ->values()->all();
    }

    public function periods()
    {
        return $this->hasMany(DoctorAttendancePeriod::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function day()
    {
        return $this->belongsTo(Day::class);
    }
}
