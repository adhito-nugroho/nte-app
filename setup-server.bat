@echo off
setlocal enabledelayedexpansion

:: =======================================================
:: SETUP AWAL SERVER — NTE (Monitoring NTE KTH)
:: Jalankan SEKALI SAJA untuk menyiapkan folder aplikasi di server
:: sebagai repository Git yang terhubung ke GitHub.
::
:: Aman dijalankan pada folder yang sudah berisi file aplikasi:
:: - File kode yang dilacak Git akan disamakan dengan isi GitHub.
:: - Folder uploads dan vendor TIDAK disentuh.
:: =======================================================

:: --- SSH ke server (Cloudflare Tunnel) ---
set SERVER_USER=adit
set SERVER_IP=127.0.0.1
set SERVER_PORT=2222

:: --- Direktori aplikasi di server ---
set "REMOTE_DIR=C:\laragon\www\website-cdk\nte-app"

:: --- Branch yang di-deploy ---
set BRANCH=main

:: --- Path binary git di server (Windows Laragon) ---
set "REMOTE_GIT=C:\laragon\bin\git\cmd\git.exe"

set "REPO_URL=https://github.com/adhito-nugroho/nte-app.git"

echo ======================================================
echo  NTE ^| SETUP AWAL SERVER
echo  Repo   : %REPO_URL%
echo  Target : %REMOTE_DIR%
echo ======================================================
echo.
echo [PERINGATAN] Script ini dijalankan SEKALI SAJA.
echo Setelah ini, gunakan deploy-git.bat untuk update rutin.
echo Folder uploads dan vendor di server tidak akan dihapus.
echo.
pause

echo.
echo [1/4] Mendaftarkan folder sebagai safe.directory...
ssh -p %SERVER_PORT% %SERVER_USER%@%SERVER_IP% "%REMOTE_GIT% config --global --add safe.directory %REMOTE_DIR:\=/%"
if errorlevel 1 goto :fail

echo.
echo [2/4] Inisialisasi repository Git (bila belum ada)...
ssh -p %SERVER_PORT% %SERVER_USER%@%SERVER_IP% "cd /d %REMOTE_DIR% && if not exist .git %REMOTE_GIT% init -b %BRANCH%"
if errorlevel 1 goto :fail

echo.
echo [3/4] Menyetel remote origin...
ssh -p %SERVER_PORT% %SERVER_USER%@%SERVER_IP% "cd /d %REMOTE_DIR% && (%REMOTE_GIT% remote remove origin >nul 2>nul & %REMOTE_GIT% remote add origin %REPO_URL%)"
if errorlevel 1 goto :fail

echo.
echo [4/4] Mengambil kode dari GitHub, uploads dan vendor dipertahankan...
ssh -p %SERVER_PORT% %SERVER_USER%@%SERVER_IP% "cd /d %REMOTE_DIR% && %REMOTE_GIT% fetch origin %BRANCH% && %REMOTE_GIT% reset --hard origin/%BRANCH%"
if errorlevel 1 goto :fail

echo.
echo ======================================================
echo  SETUP AWAL SELESAI!
echo  Lanjutkan dengan deploy-git.bat untuk update rutin.
echo ======================================================
pause
exit /b 0

:fail
echo.
echo [ERROR] Setup awal gagal. Periksa pesan error di atas.
echo Pastikan SSH tunnel aktif, password benar, dan folder ada di server.
pause
exit /b 1
