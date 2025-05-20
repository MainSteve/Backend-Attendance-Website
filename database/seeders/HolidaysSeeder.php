<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

class HolidaysSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Clear existing holidays
        Holiday::truncate();
        
        // Indonesian Public Holidays 2025 (excluding 'Cuti Bersama')
        $holidays = [
            [
                'name' => 'Tahun Baru',
                'date' => '2025-01-01',
                'description' => 'Tahun Baru Masehi 2025',
                'is_recurring' => true
            ],
            [
                'name' => 'Tahun Baru Imlek 2576 Kongzili',
                'date' => '2025-01-29',
                'description' => 'Tahun Baru China',
                'is_recurring' => false
            ],
            [
                'name' => 'Isra Mikraj Nabi Muhammad SAW',
                'date' => '2025-02-28',
                'description' => 'Peringatan Isra dan Mi\'raj Nabi Muhammad SAW',
                'is_recurring' => false
            ],
            [
                'name' => 'Hari Raya Nyepi Tahun Baru Saka 1947',
                'date' => '2025-03-30',
                'description' => 'Tahun Baru Hindu',
                'is_recurring' => false
            ],
            [
                'name' => 'Hari Raya Idul Fitri 1446 Hijriah',
                'date' => '2025-04-01',
                'description' => 'Hari pertama Idul Fitri',
                'is_recurring' => false
            ],
            [
                'name' => 'Hari Raya Idul Fitri 1446 Hijriah',
                'date' => '2025-04-02',
                'description' => 'Hari kedua Idul Fitri',
                'is_recurring' => false
            ],
            [
                'name' => 'Hari Raya Idul Fitri 1446 Hijriah',
                'date' => '2025-04-03',
                'description' => 'Hari ketiga Idul Fitri',
                'is_recurring' => false
            ],
            [
                'name' => 'Hari Raya Idul Fitri 1446 Hijriah',
                'date' => '2025-04-04',
                'description' => 'Hari keempat Idul Fitri',
                'is_recurring' => false
            ],
            [
                'name' => 'Wafat Isa Al-Masih',
                'date' => '2025-04-18',
                'description' => 'Jumat Agung (Good Friday)',
                'is_recurring' => false
            ],
            [
                'name' => 'Hari Buruh Internasional',
                'date' => '2025-05-01',
                'description' => 'Hari Pekerja Internasional',
                'is_recurring' => true
            ],
            [
                'name' => 'Kenaikan Isa Al-Masih',
                'date' => '2025-05-29',
                'description' => 'Hari Kenaikan Yesus Kristus',
                'is_recurring' => false
            ],
            [
                'name' => 'Hari Raya Waisak 2569',
                'date' => '2025-06-01',
                'description' => 'Hari raya umat Buddha',
                'is_recurring' => false
            ],
            [
                'name' => 'Hari Lahir Pancasila',
                'date' => '2025-06-01',
                'description' => 'Peringatan hari lahirnya Pancasila',
                'is_recurring' => true
            ],
            [
                'name' => 'Hari Kemerdekaan Republik Indonesia',
                'date' => '2025-08-17',
                'description' => 'Peringatan Proklamasi Kemerdekaan Republik Indonesia',
                'is_recurring' => true
            ],
            [
                'name' => 'Maulid Nabi Muhammad SAW',
                'date' => '2025-09-07',
                'description' => 'Peringatan kelahiran Nabi Muhammad SAW',
                'is_recurring' => false
            ],
            [
                'name' => 'Hari Raya Natal',
                'date' => '2025-12-25',
                'description' => 'Peringatan kelahiran Yesus Kristus',
                'is_recurring' => true
            ]
        ];
        
        foreach ($holidays as $holiday) {
            Holiday::create($holiday);
        }

        $this->command->info('Holidays seeded successfully!');
    }
}
