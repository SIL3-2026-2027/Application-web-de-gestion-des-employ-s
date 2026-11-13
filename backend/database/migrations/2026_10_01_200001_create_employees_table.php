<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('matricule', 20)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            // Identité
            $table->enum('civility', ['M.', 'Mme'])->nullable();
            $table->string('last_name');
            $table->string('first_name');
            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();
            $table->string('nationality')->nullable();
            $table->text('social_security_number')->nullable(); // chiffré
            $table->enum('marital_status', ['celibataire', 'marie', 'pacse', 'divorce', 'veuf'])->nullable();
            $table->unsignedTinyInteger('children_count')->default(0);
            $table->string('photo_path')->nullable();

            // Coordonnées
            $table->string('personal_email')->nullable();
            $table->string('work_email')->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->text('iban')->nullable(); // chiffré

            // Poste
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('hire_date');
            $table->enum('status', ['actif', 'periode_essai', 'suspendu', 'sorti'])->default('actif');
            $table->date('exit_date')->nullable();
            $table->string('exit_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
            $table->index('status');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('manager_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
        });
        Schema::dropIfExists('employees');
    }
};
