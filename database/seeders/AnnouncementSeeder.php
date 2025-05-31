<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\User;

class AnnouncementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first admin user
        $admin = User::where('role', 'admin')->first();
        
        if (!$admin) {
            $this->command->warn('No admin user found. Please create an admin user first.');
            return;
        }

        // Create departments if they don't exist
        $departmentNames = [
            'Administrasi',
            'Pelaksanaan Program',
            'Kerjasama',
            'Maintenance',
            'Cybersecurity',
            'Recruitment',
            'Development',
            'Media',
            'Content',
            'Officials'
        ];

        $departments = collect();
        foreach ($departmentNames as $name) {
            $department = Department::firstOrCreate(['name' => $name]);
            $departments->push($department);
        }

        // Sample announcements with multiple departments
        $announcements = [
            [
                'title' => 'Welcome to the New Year 2025!',
                'content' => 'We wish everyone a productive and successful year ahead. Let\'s work together to achieve our goals and maintain our high standards of excellence throughout the organization.',
                'importance_level' => 3, // High - 1 year
                'departments' => ['Administrasi', 'Officials'], // All departments
            ],
            [
                'title' => 'Monthly All-Hands Meeting',
                'content' => 'Please join us for our monthly all-hands meeting this Friday at 2 PM in the main conference room. We will discuss current projects, upcoming deadlines, and organizational objectives.',
                'importance_level' => 2, // Medium - 1 month
                'departments' => ['Administrasi', 'Pelaksanaan Program', 'Officials'],
            ],
            [
                'title' => 'System Maintenance Notice - Critical',
                'content' => 'The company servers will undergo scheduled maintenance this weekend from 10 PM Saturday to 6 AM Sunday. Please save your work and log out by 9:30 PM on Saturday. This affects all digital systems.',
                'importance_level' => 1, // Low - 3 days
                'departments' => ['Development', 'Cybersecurity', 'Maintenance'],
            ],
            [
                'title' => 'New Company-Wide Security Policies',
                'content' => 'Please review the updated cybersecurity policies in the employee handbook. All employees must acknowledge these changes and complete the security training by the end of this month.',
                'importance_level' => 3, // High - 1 year
                'departments' => ['Cybersecurity', 'Administrasi', 'Officials'],
            ],
            [
                'title' => 'Coffee Machine Maintenance - Floor 3',
                'content' => 'The coffee machine on the 3rd floor is temporarily out of order due to scheduled maintenance. Please use the machines on the 2nd or 4th floor until repairs are completed.',
                'importance_level' => 1, // Low - 3 days
                'departments' => ['Maintenance', 'Administrasi'],
            ],
            [
                'title' => 'Quarterly Performance Reviews Schedule',
                'content' => 'Quarterly performance reviews will begin next week. Please prepare your self-assessment and have it ready for your scheduled meeting with your supervisor. HR will send individual schedules.',
                'importance_level' => 2, // Medium - 1 month
                'departments' => ['Recruitment', 'Administrasi', 'Officials'],
            ],
            [
                'title' => 'Emergency Evacuation Drill - All Buildings',
                'content' => 'We will conduct an emergency evacuation drill tomorrow at 10 AM across all office buildings. Please familiarize yourself with the evacuation routes and assembly points posted in your work areas.',
                'importance_level' => 1, // Low - 3 days
                'departments' => ['Administrasi', 'Maintenance', 'Officials'],
            ],
            [
                'title' => 'New Employee Benefits Package 2025',
                'content' => 'We are excited to announce our enhanced employee benefits package for 2025, including improved health insurance coverage, additional vacation days, and professional development allowances. Detailed information will be sent via email.',
                'importance_level' => 3, // High - 1 year
                'departments' => ['Recruitment', 'Administrasi', 'Officials'],
            ],
            [
                'title' => 'Content Strategy Workshop',
                'content' => 'Join us for a comprehensive content strategy workshop next Tuesday. We\'ll cover new content guidelines, brand voice standards, and upcoming campaign requirements for Q2.',
                'importance_level' => 2, // Medium - 1 month
                'departments' => ['Content', 'Media'],
            ],
            [
                'title' => 'New Partnership Opportunities',
                'content' => 'We have several exciting partnership opportunities in the pipeline. The cooperation team will be hosting a briefing session to discuss potential collaborations and strategic alliances.',
                'importance_level' => 2, // Medium - 1 month
                'departments' => ['Kerjasama', 'Officials', 'Administrasi'],
            ],
            [
                'title' => 'Development Team Code Review Session',
                'content' => 'Monthly code review session scheduled for this Thursday. All developers are required to attend. We\'ll review recent projects, discuss best practices, and plan upcoming sprints.',
                'importance_level' => 1, // Low - 3 days
                'departments' => ['Development'],
            ],
            [
                'title' => 'Media Asset Management Update',
                'content' => 'The media team has updated our digital asset management system. Please review the new organization structure and tagging conventions. Training sessions available upon request.',
                'importance_level' => 2, // Medium - 1 month
                'departments' => ['Media', 'Content'],
            ],
        ];

        foreach ($announcements as $announcementData) {
            $departmentNames = $announcementData['departments'];
            unset($announcementData['departments']);
            
            $announcementData['created_by'] = $admin->id;
            
            $announcement = Announcement::create($announcementData);
            
            // Attach departments
            $departmentIds = $departments->whereIn('name', $departmentNames)->pluck('id')->toArray();
            $announcement->departments()->attach($departmentIds);
        }

        $this->command->info('Announcement seeder completed successfully!');
        $this->command->info('Created ' . count($announcements) . ' sample announcements with multiple department assignments.');
        $this->command->info('Created ' . $departments->count() . ' departments: ' . $departments->pluck('name')->implode(', '));
    }
}
