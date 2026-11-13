<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRS-02 : horaires types
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // {"1": {"start": "09:00", "end": "17:30", "break": 60}, …} ; jour absent = non travaillé (1 = lundi … 7 = dimanche)
            $table->json('days');
            $table->unsignedSmallInteger('late_tolerance')->default(10); // minutes
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('work_schedule_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
        });

        // PRS-01 : pointages (plusieurs plages par jour possibles, ex. pause déjeuner)
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('clock_in');
            $table->dateTime('clock_out')->nullable();
            $table->enum('source', ['application', 'correction', 'saisie_rh'])->default('application');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'date']);
        });

        // PRS-04 : heures supplémentaires déclarées et validées
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('minutes');
            $table->string('reason');
            $table->enum('status', ['en_attente', 'approuve', 'refuse'])->default('en_attente');
            $table->string('refusal_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'date']);
        });

        // PRS-05 : demandes de correction de pointage
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->json('segments'); // [{"in": "08:55", "out": "12:30"}, …]
            $table->string('reason');
            $table->enum('status', ['en_attente', 'approuve', 'refuse'])->default('en_attente');
            $table->string('refusal_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('attendances');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_schedule_id');
        });
        Schema::dropIfExists('work_schedules');
    }
};
