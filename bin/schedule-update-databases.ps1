# Регистрирует (или обновляет) задачу Планировщика заданий Windows, которая раз в неделю запускает
# bin/update-databases.php. Запуск один раз из корня проекта или из любой папки:
#   powershell -ExecutionPolicy Bypass -File bin\schedule-update-databases.ps1
# Повторный запуск заменяет задачу с тем же именем, вторая не создаётся.

$ErrorActionPreference = 'Stop'

$TaskName   = 'Site Diagnostics - обновление баз'
$PhpPath    = 'C:\OSPanel\modules\PHP-8.3\PHP\php.exe'
$ProjectDir = Split-Path -Parent $PSScriptRoot
$Script     = Join-Path $ProjectDir 'bin\update-databases.php'
$DayOfWeek  = 'Monday'
$StartTime  = '10:00'

if (-not (Test-Path $PhpPath)) {
    throw "Не найден PHP OpenServer: $PhpPath"
}
if (-not (Test-Path $Script)) {
    throw "Не найден скрипт: $Script"
}

$action   = New-ScheduledTaskAction -Execute $PhpPath -Argument "`"$Script`"" -WorkingDirectory $ProjectDir
$trigger  = New-ScheduledTaskTrigger -Weekly -DaysOfWeek $DayOfWeek -At $StartTime
# Пропущенный запуск (ПК был выключен) выполняется при первой возможности; без батареи — не останавливать
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
    -Description 'Обновляет базы версий Site Diagnostics (bin/update-databases.php).' -Force | Out-Null

$task = Get-ScheduledTask -TaskName $TaskName
$info = Get-ScheduledTaskInfo -TaskName $TaskName
Write-Output "Задача «$TaskName» зарегистрирована: $($task.State), следующий запуск — $($info.NextRunTime)"
