@echo off
setlocal enabledelayedexpansion

:: =======================================================
:: KONFIGURASI — NTE (Monitoring NTE KTH)
:: Repository  : https://github.com/adhito-nugroho/nte-app.git
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
echo  NTE ^| Monitoring NTE KTH — DEPLOY GIT PULL
echo  Repo : %REPO_URL%
echo  Branch : %BRANCH%
echo ======================================================
echo.

:: 0. Pastikan folder lokal sudah menjadi repository Git.
git rev-parse --is-inside-work-tree >nul 2>nul
if errorlevel 1 (
    echo [ERROR] Folder ini belum berupa repository Git.
    echo Jalankan SEKALI di D:\laragon\www\nte-app:
    echo    git init
    echo    git remote add origin %REPO_URL%
    echo    git add .
    echo    git commit -m "Initial commit"
    echo    git branch -M %BRANCH%
    echo    git push -u origin %BRANCH%
    pause
    exit /b 1
)

:: 1. Commit lokal (jika ada perubahan) lalu push ke GitHub
echo [1/3] Mendorong perubahan lokal ke GitHub...
echo.

git add .

set "NEED_COMMIT=0"
for /f %%i in ('git diff --cached --name-only') do set "NEED_COMMIT=1"

if "!NEED_COMMIT!"=="0" (
    echo Tidak ada perubahan lokal yang perlu di-commit.
    echo Melanjutkan push/pull...
) else (
    set /p msg="Masukkan pesan commit (Enter = 'update aplikasi NTE'): "
    if "!msg!"=="" set "msg=update aplikasi NTE"

    git commit -m "!msg!"
    if errorlevel 1 (
        echo.
        echo [ERROR] Gagal melakukan git commit!
        pause
        exit /b 1
    )
)

git push origin %BRANCH%
if errorlevel 1 (
    echo.
    echo [ERROR] Gagal git push ke GitHub!
    echo Pastikan Anda sudah login ke GitHub dan remote sudah dikonfigurasi.
    pause
    exit /b 1
)

echo Push berhasil!

:: 2. Deploy kode ke server via SSH.
:: uploads SEBAGAI SENGAJA tidak disentuh agar arsip file import yang sudah
:: ada di server tetap utuh. vendor juga tidak ditimpa (jalankan
:: "composer install" sekali di server bila dependency berubah).
echo.
echo [2/3] Mengunduh dan menimpa update kode ke server...
echo *(Jika diminta password SSH, masukkan password akun server)*
echo.
echo CATATAN: Folder uploads (arsip import) di server tidak akan diubah.
echo.

:: Path kode/aset yang di-deploy (uploads & vendor tidak termasuk).
set "DEPLOY_PATHS=assets config.php footer.php header.php import.php import_target.php index.php laporan_penyuluh.php laporan_produk.php laporan_realisasi_penyuluh.php master_penyuluh.php merge_penyuluh.php target.php transaksi.php tests composer.json composer.lock deploy-git.bat .gitignore"

:: Hapus index.lock, fetch, lalu checkout hanya path kode dari origin.
ssh -p %SERVER_PORT% %SERVER_USER%@%SERVER_IP% "%REMOTE_GIT% config --global --add safe.directory %REMOTE_DIR:\=/% && %%SystemRoot%%\System32\attrib.exe -R -S -H %REMOTE_DIR%\.git\index >nul 2>nul & del /F /Q %REMOTE_DIR%\.git\index.lock >nul 2>nul & %REMOTE_GIT% -C %REMOTE_DIR% fetch origin %BRANCH% && %REMOTE_GIT% -C %REMOTE_DIR% read-tree HEAD && %REMOTE_GIT% -C %REMOTE_DIR% checkout origin/%BRANCH% -- %DEPLOY_PATHS% && %REMOTE_GIT% -C %REMOTE_DIR% update-ref refs/heads/%BRANCH% origin/%BRANCH%"

if errorlevel 1 (
    echo.
    echo [ERROR] Deploy kode di server gagal.
    echo Periksa pesan error di atas (mis. .git\index rusak atau file kode terkunci).
    echo Jika folder server belum pernah di-clone, jalankan SEKALI:
    echo    ssh -p %SERVER_PORT% %SERVER_USER%@%SERVER_IP% "%REMOTE_GIT% clone %REPO_URL% %REMOTE_DIR%"
    pause
    exit /b 1
)

echo Git Pull di server berhasil!

:: 3. Verifikasi commit yang aktif di server
echo.
echo [3/3] Verifikasi versi di server...

ssh -p %SERVER_PORT% %SERVER_USER%@%SERVER_IP% "%REMOTE_GIT% -C %REMOTE_DIR% log -1 --oneline"

if errorlevel 1 (
    echo.
    echo [PERINGATAN] Gagal membaca log Git di server. Deploy tetap dianggap selesai.
)

echo.
echo ======================================================
echo  DEPLOYMENT BERHASIL!
echo  URL Server : http://%SERVER_IP%/website-cdk/nte-app (sesuaikan)
echo ======================================================
pause
