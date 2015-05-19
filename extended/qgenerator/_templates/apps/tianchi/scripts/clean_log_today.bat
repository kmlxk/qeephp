@ECHO OFF
cd ../log
for /F "tokens=1,2,3,4 delims=-/ " %%a in ('date/T') do set Cdate=%%a%%b%%c
set filename=%Cdate%
del *%filename%*.log > nul
pause