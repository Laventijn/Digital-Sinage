@echo off
title Digital-Sinage - VS Code setup helper
echo.
echo ================================================
echo Digital-Sinage - VS Code setup helper
echo ================================================
echo.
echo Dit script helpt je het project lokaal in Visual Studio Code te testen.
echo Het controleert of de belangrijkste tools aanwezig zijn en kan:
echo - Visual Studio Code openen
echo - Een lokale PHP-built-in server starten op http://localhost:8000
echo
echo Vereiste (aanbevolen) software:
echo - Git: https://git-scm.com/downloads
echo - Visual Studio Code: https://code.visualstudio.com/download
echo - PHP 7.4+: https://www.php.net/downloads.php
echo
pause

set "MISSING=0"
where git >nul 2>&1 || (
  echo [!] Git niet gevonden. Installeer: https://git-scm.com/downloads
  set "MISSING=1"
)
where code >nul 2>&1 || (
  echo [!] Visual Studio Code niet gevonden. Installeer: https://code.visualstudio.com/download
  set "MISSING=1"
)
where php >nul 2>&1 || (
  echo [!] PHP niet gevonden. Voor lokaal testen is PHP aanbevolen: https://www.php.net/downloads.php
  set "MISSING=1"
)

if "%MISSING%"=="1" (
  echo.
  set /p CONT="Wilt u doorgaan ondanks ontbrekende requirements? (j/n): "
  if /I "%CONT%"=="n" (
    echo Afgebroken. Installeer eerst de aanbevolen software.
    goto :END
  )
)

pushd "%~dp0\.."
echo Project directory: %cd%
echo.

choice /M "Open dit project in Visual Studio Code?"
if %errorlevel%==1 (
  where code >nul 2>&1 && (
    start "VS Code" code "%cd%"
    echo Visual Studio Code wordt geopend...
  ) || (
    echo Kan 'code' commando niet vinden. Open het project handmatig in VS Code.
  )
)

choice /M "Start lokale PHP-server op http://localhost:8000?"
if %errorlevel%==1 (
  where php >nul 2>&1 || (
    echo Kan PHP niet vinden. Sla starten van server over.
  )
  where php >nul 2>&1 && (
    echo Starten van de PHP server in een nieuw venster...
    start "PHP Server" cmd /k "php -S localhost:8000 -t \"%cd%\""
    echo PHP server gestart op http://localhost:8000 (nieuw venster)
  )
)

echo.
set /p OPEN_README="Wilt u het README-bestand in deze map openen? (j/n): "
if /I "%OPEN_README%"=="j" (
  if exist "%~dp0\README.md" (
    start "README" notepad "%~dp0\README.md"
  ) else (
    echo README.md niet gevonden in deze map.
  )
)

popd
:END
echo.
echo Klaar.
pause
