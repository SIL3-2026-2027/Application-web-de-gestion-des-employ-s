<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CNG-01 : types de congés paramétrables
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('color', 7)->default('#2e75b6');
            $table->boolean('is_deductible')->default(true);      // décompté d'un solde
            $table->decimal('monthly_accrual', 5, 2)->nullable(); // ex. 2 jours acquis par mois
            $table->decimal('annual_allowance', 5, 2)->nullable(); // droits annuels (plafond ou forfait)
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('requires_hr_approval')->default(false); // 2e niveau de validation
            $table->boolean('employee_can_request')->default(true);  // sinon saisi par les RH (maladie…)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // CNG-07 : jours fériés
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->timestamps();
        });

        // CNG-02 / CNG-03 / CNG-06 : demandes et absences
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('start_half_day')->default(false); // commence l'après-midi
            $table->boolean('end_half_day')->default(false);   // se termine à midi
            $table->decimal('days', 5, 1);
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->enum('status', ['en_attente', 'valide_manager', 'approuve', 'refuse', 'annule'])->default('en_attente');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('manager_decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_decided_at')->nullable();
            $table->foreignId('hr_decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hr_decided_at')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'start_date']);
            $table->index('status');
        });

        // CNG-04 : ajustements manuels des soldes, avec justification
        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('days', 5, 1); // positif ou négatif
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_adjustments');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('leave_types');
    }
};
