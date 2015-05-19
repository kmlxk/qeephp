@ECHO OFF
set curdir=%cd%
call clean_cache.cmd
echo Çå³ıÈÕÖ¾
cd %curdir%
cd ../log
del *.log > nul
pause