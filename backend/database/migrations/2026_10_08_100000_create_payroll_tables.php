<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rubriques de paie : ce que l'on transmet au logiciel de paie, avec son code d'export
        Schema::create('payroll_rubrics', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();         // code interne ; = code du type de congé pour les absences
            $table->string('label');
            $table->enum('kind', ['gain', 'retenue', 'information'])->default('gain');
            $table->enum('unit', ['montant', 'heures', 'jours'])->default('montant');
            $table->string('export_code', 30)->nullable(); // code de la rubrique dans le logiciel de paie
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // PAI-01 : éléments fixes récurrents (prime mensuelle, avantage en nature…)
        Schema::create('recurring_pay_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_rubric_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->char('start_month', 7);               // AAAA-MM
            $table->char('end_month', 7)->nullable();
            $table->string('comment')->nullable();
            $table->timestamps();
        });

        // PAI-03 : périodes mensuelles, ouvertes puis clôturées
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique();           // AAAA-MM
            $table->enum('status', ['ouverte', 'cloturee'])->default('ouverte');
            $table->timestamp('computed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // PAI-02 : éléments variables du mois
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_rubric_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 8, 2)->nullable(); // heures ou jours
            $table->decimal('amount', 10, 2)->nullable();
            // manuel : saisi par les RH ; les autres sont recalculés à chaque « Préparer »
            $table->enum('source', ['manuel', 'recurrent', 'heures_sup', 'conge', 'absence'])->default('manuel');
            $table->string('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payroll_period_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('recurring_pay_items');
        Schema::dropIfExists('payroll_rubrics');
    }
};
