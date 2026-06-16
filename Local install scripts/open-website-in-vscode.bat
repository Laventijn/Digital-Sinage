@echo off
REM Open the website folder in VS Code, start PHP built-in server and open browser.

REM Change this path if your workspace is elsewhere
setlocal EnableDelayedExpansion

REM Default settings (aanpasbaar)
set "SITE_DIR=C:\Users\rombauva\OneDrive - Onderwijs Gent\Apps\VS_PI\Digital-Sinage\RaspberryPi\var\www\html\"
set "PORT=%1"
if "%PORT%"=="" set "PORT=8000"
set "PHP_ARG=%2"
set "REMOTE_FLAG=%3"
set "REMOTE_TARGET=%4"

if not exist "%SITE_DIR%" (
  echo Fout: map niet gevonden: %SITE_DIR%
  echo Controleer het pad bovenin %~f0 of geef een ander pad als eerste argument.
  pause
  exit /b 1
)

pushd "%SITE_DIR%"

REM Detecteer php: eerst argument, dan `where`, dan veelvoorkomende paden
if not "%PHP_ARG%"=="" (
  set "PHP_PATH=%PHP_ARG%"
)
if not defined PHP_PATH (
  for /f "delims=" %%p in ('where php 2^>nul') do if not defined PHP_PATH set "PHP_PATH=%%p"
)
if not defined PHP_PATH if exist "C:\php\php.exe" set "PHP_PATH=C:\php\php.exe"
if not defined PHP_PATH if exist "C:\Program Files\PHP\php.exe" set "PHP_PATH=C:\Program Files\PHP\php.exe"

REM Detecteer code (VS Code CLI)
for /f "delims=" %%c in ('where code 2^>nul') do if not defined CODE_PATH set "CODE_PATH=%%c"

REM Start VS Code (remote of lokaal)
if defined CODE_PATH (
  if /i "%REMOTE_FLAG%"=="--remote" if not "%REMOTE_TARGET%"=="" (
    echo Opening VS Code remote: %REMOTE_TARGET%
    start "VSCode Remote" cmd /c ""%CODE_PATH%" --remote %REMOTE_TARGET% ."
  ) else (
    start "VSCode" cmd /c ""%CODE_PATH%" ."
  )
)
else (
  echo Waarschuwing: 'code' CLI niet gevonden in PATH. Om dit te repareren: open VS Code -> Command Palette -> 'Shell Command: Install 'code' command in PATH' (of voeg handmatig toe).
)

REM Start PHP-server als php beschikbaar is
if defined PHP_PATH (
  echo Starten PHP ingebouwde server op poort %PORT% met %PHP_PATH% in %SITE_DIR%
  start "PHP Server" cmd /k "cd /D "%SITE_DIR%" && "%PHP_PATH%" -S localhost:%PORT%"
) else (
  echo Waarschuwing: php niet gevonden. Geef optioneel het pad als tweede argument, of installeer PHP en voeg het aan PATH toe.
)

REM Open browser naar lokale server
start "" "http://localhost:%PORT%/"

popd

echo Klaar.
endlocal
exit /b 0
