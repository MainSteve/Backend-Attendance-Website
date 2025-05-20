<?php

namespace Database\Seeders;

use App\Models\WorkingHour;
use Illuminate\Database\Seeder;

class WorkingHoursSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Define work schedules
        $regularSchedule = [
            'monday' => ['start_time' => '08:00', 'end_time' => '17:00'],
            'tuesday' => ['start_time' => '08:00', 'end_time' => '17:00'],
            'wednesday' => ['start_time' => '08:00', 'end_time' => '17:00'],
            'thursday' => ['start_time' => '08:00', 'end_time' => '17:00'],
            'friday' => ['start_time' => '08:00', 'end_time' => '16:00'],
        ];
        
        $flexibleSchedule = [
            'monday' => ['start_time' => '09:30', 'end_time' => '18:30'],
            'tuesday' => ['start_time' => '09:30', 'end_time' => '18:30'],
            'wednesday' => ['start_time' => '09:30', 'end_time' => '18:30'],
            'thursday' => ['start_time' => '09:30', 'end_time' => '18:30'],
            'friday' => ['start_time' => '09:00', 'end_time' => '17:00'],
        ];
        
        // Clear existing working hours for these users
        WorkingHour::whereIn('user_id', [1, 3])->delete();
        
        // Create working hours for user_id 1 (Regular schedule with weekend)
        foreach ($regularSchedule as $day => $hours) {
            WorkingHour::create([
                'user_id' => 1,
                'day_of_week' => $day,
                'start_time' => $hours['start_time'],
                'end_time' => $hours['end_time'],
            ]);
        }
        
        // Add weekend half-day for user 1
        WorkingHour::create([
            'user_id' => 1,
            'day_of_week' => 'saturday',
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        
        // Create working hours for user_id 3 (Flexible schedule)
        foreach ($flexibleSchedule as $day => $hours) {
            WorkingHour::create([
                'user_id' => 3,
                'day_of_week' => $day,
                'start_time' => $hours['start_time'],
                'end_time' => $hours['end_time'],
            ]);
        }

        $this->command->info('Working hours seeded successfully!');
    }
}
