<?php
function attendanceSettings(): array {
    return [
        'demo_anytime_clock_in' => true,
        'shift_start' => '08:00:00',
        'absent_after_minutes' => 60,
        'lunch_start' => '12:00:00',
        'lunch_end' => '13:00:00',
        'shift_end' => '17:00:00',
        'auto_clock_out_grace_minutes' => 30,
        'day_off_iso' => 2,
        'day_off_label' => 'Tuesday',
        'standard_work_minutes' => 480,
    ];
}

function employeeAttendanceSettings(int $employeeId): array {
    $settings = attendanceSettings();
    $isGroupA = $employeeId % 2 === 1;
    $settings['schedule_group'] = $isGroupA ? 'A' : 'B';
    $settings['lunch_start'] = $isGroupA ? '11:00:00' : '13:00:00';
    $settings['lunch_end'] = $isGroupA ? '12:00:00' : '14:00:00';
    $settings['day_off_iso'] = $isGroupA ? 2 : 3;
    $settings['day_off_label'] = $isGroupA ? 'Tuesday' : 'Wednesday';
    return $settings;
}
