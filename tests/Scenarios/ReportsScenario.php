<?php

namespace Tests\Scenarios;

use App\Models\User;

class ReportsScenario
{
    public function __construct(
        public User $admin,
        public User $userWithoutPermission,
    ) {}

    public static function make(): ReportsScenario
    {
        $admin = createUserWithPermissions(['read-all-reports']);
        $userWithoutPermission = User::factory()->create();

        return new ReportsScenario($admin, $userWithoutPermission);
    }
}
