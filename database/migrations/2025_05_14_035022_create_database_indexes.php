<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add indexes for better performance
        Schema::table('attendances', function (Blueprint $table) {
            $table->index('user_id', 'idx_attendances_user_id');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->index('user_id', 'idx_leave_requests_user_id');
        });

        Schema::table('leave_quotas', function (Blueprint $table) {
            $table->index(['user_id', 'year'], 'idx_leave_quotas_user_id_year');
        });

        Schema::table('working_hours', function (Blueprint $table) {
            $table->index('user_id', 'idx_working_hours_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('department_id', 'idx_users_department_id');
            $table->index('email', 'idx_users_email');
        });

        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->index(['tokenable_type', 'tokenable_id'], 'idx_personal_access_tokens_tokenable');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('idx_attendances_user_id');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex('idx_leave_requests_user_id');
        });

        Schema::table('leave_quotas', function (Blueprint $table) {
            $table->dropIndex('idx_leave_quotas_user_id_year');
        });

        Schema::table('working_hours', function (Blueprint $table) {
            $table->dropIndex('idx_working_hours_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_department_id');
            $table->dropIndex('idx_users_email');
        });

        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropIndex('idx_personal_access_tokens_tokenable');
            });
        }
    }
};
