@ECHO OFF
set curdir=%cd%
call clean_cache.cmd
echo �����־
cd %curdir%
cd ../log
del *.log > nul
pause