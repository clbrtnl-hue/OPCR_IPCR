<?php

use App\Services\InactiveAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Forms already parked on a deactivated reviewer or commitment approver
 * move on once. Later deactivations do this themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(InactiveAccount::class)->sweep();
    }

    public function down(): void
    {
        // The forms have a current reviewer again. They stay where they moved.
    }
};
