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
        // 1. Drop foreign keys in dependent tables
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('task_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('leave_quotas', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('working_hours', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });
        Schema::table('leave_request_proofs', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
        });

        // 2. Change users table
        Schema::table('users', function (Blueprint $table) {
            $table->dropPrimary('users_pkey');
            $table->dropColumn('id');
            $table->unsignedBigInteger('id')->primary();
        });

        // 3. Re-add foreign keys
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });
        Schema::table('task_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });
        Schema::table('leave_quotas', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });
        Schema::table('working_hours', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });
        Schema::table('announcements', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users');
        });
        Schema::table('leave_request_proofs', function (Blueprint $table) {
            $table->foreign('verified_by')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop and re-add foreign keys in reverse order if needed
        Schema::table('leave_request_proofs', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
        });
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });
        Schema::table('working_hours', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('leave_quotas', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('task_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropColumn('id');
            $table->id(); // Adds auto-incrementing id
        });
    }
};
