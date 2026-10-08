@echo off
title Aquamarine Laundry Management Service System
color 0A
echo ===================================================
echo     Aquamarine Laundry Management Service System
echo ===================================================
echo.
echo Starting offline local server at http://localhost:8000 ...
echo Press Ctrl+C in this window to stop the server when done.
echo.
:: Open default web browser to localhost:8000 after 2 seconds
start "" "http://localhost:8000"

:: Start local PHP server
C:\php\php.exe -S localhost:8000