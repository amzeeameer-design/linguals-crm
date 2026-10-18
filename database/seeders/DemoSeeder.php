<?php

namespace Database\Seeders;

use App\Models\TalentApplication;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo data only allowed locally.');
        }foreach ([['Ayesha Khan', 'individual', 'Translation', 'submitted'], ['Global Languages', 'company', 'Translation', 'under_review'], ['Omar Ali', 'individual', 'Voice Over', 'submitted']] as $i => $row) {
            $a = TalentApplication::firstOrCreate(['email' => 'demo'.($i + 1).'@example.test'], ['reference' => (string) Str::uuid(), 'name' => $row[0], 'applicant_type' => $row[1], 'status' => $row[3], 'country' => 'Pakistan', 'timezone' => 'UTC +05:00', 'profile' => ['demo' => true, 'about' => 'Sample record for local review'], 'submitted_at' => now()]);
            $a->services()->firstOrCreate(['service' => $row[2]], ['experience_years' => 5]);
        }
    }
}
