<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    protected $fillable = ['month', 'status', 'computed_at', 'closed_at', 'closed_by'];

    protected function casts(): array
    {
        return ['computed_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === 'cloturee';
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    public function end(): CarbonImmutable
    {
        return $this->start()->endOfMonth()->startOfDay();
    }
}
