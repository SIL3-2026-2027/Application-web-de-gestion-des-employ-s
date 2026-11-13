<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmployeeDocument extends Model
{
    use LogsActivity;

    protected $fillable = [
        'employee_id', 'document_category_id', 'title', 'file_path', 'original_name', 'mime_type', 'size',
        'expires_at', 'source', 'notes', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'date', 'size' => 'integer'];
    }

    protected static function booted(): void
    {
        // Le fichier disparaît avec la ligne
        static::deleted(fn (EmployeeDocument $d) => Storage::disk('local')->delete($d->file_path));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'document_category_id', 'expires_at', 'original_name'])
            ->logOnlyDirty()
            ->useLogName('document');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Documents que l'utilisateur peut voir pour cet employé, selon la catégorie. */
    public function scopeVisibleTo(Builder $query, User $user, Employee $employee): Builder
    {
        if ($user->isHr()) {
            return $query;
        }

        $column = $user->employee?->id === $employee->id ? 'visible_to_employee' : 'visible_to_manager';

        return $query->whereHas('category', fn ($q) => $q->where($column, true));
    }
}
