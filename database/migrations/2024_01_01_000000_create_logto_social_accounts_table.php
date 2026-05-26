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
        Schema::create('logto_social_accounts', function (Blueprint $table) {
            $table->id();
            
            // User foreign key (nullable to support guest users)
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            
            // Logto user ID (unique per provider)
            $table->string('logto_id', 255);
            
            // Provider (for future multi-provider support)
            $table->string('provider', 50)->default('logto');
            
            // User information from Logto
            $table->json('user_info')->nullable();
            
            // Token information
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('token_type', 50)->default('Bearer');
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            
            // Scopes
            $table->json('scopes')->nullable();
            
            // ID Token
            $table->text('id_token')->nullable();
            $table->json('id_token_claims')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Soft deletes
            $table->softDeletes();
            
            // Indexes
            $table->unique(['provider', 'logto_id']);
            $table->index(['user_id']);
            $table->index(['token_expires_at']);
        });

        // Add logto_id column to users table if it doesn't exist
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'logto_id')) {
                $table->string('logto_id', 255)->nullable()->unique();
            }
            
            if (!Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar', 500)->nullable();
            }
            
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 20)->nullable();
            }
            
            if (!Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logto_social_accounts');
        
        // Optionally remove columns from users table
        Schema::table('users', function (Blueprint $table) {
            $columns = ['logto_id', 'avatar', 'phone', 'phone_verified_at'];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
