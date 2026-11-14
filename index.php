<?php
require __DIR__ . '/auth.php';
ninsougaku_session_start();

if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$error = '';
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedId = isset($_POST['login_id']) ? trim((string) $_POST['login_id']) : '';
    $postedPassword = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $postedToken = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    $tokenOk = is_string($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $postedToken);
    if ($tokenOk && ninsougaku_check_login($postedId, $postedPassword)) {
        session_regenerate_id(true);
        $_SESSION['ninsougaku_user'] = $postedId;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: ./', true, 303);
        exit;
    }
    $error = 'IDまたはパスワードが違います';
}

if (ninsougaku_logged_in()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile(__DIR__ . '/index.html');
    exit;
}

$loginId = isset($postedId) ? $postedId : '';
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
http_response_code(200);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン | AI 人相鑑定</title>
    <link rel="icon" type="image/svg+xml" href="icon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700&display=swap');
        body { font-family: 'Noto Sans JP', sans-serif; background-color: #0f172a; color: #f1f5f9; }
        .glass-panel { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center p-4 md:p-8">
    <main class="max-w-md w-full space-y-8">
        <header class="text-center space-y-4">
            <div class="flex justify-center">
                <img src="icon.svg?v=1" alt="" class="w-28 h-28 drop-shadow-2xl">
            </div>
            <h1 class="text-3xl font-bold tracking-tight bg-clip-text text-transparent bg-gradient-to-r from-emerald-400 to-blue-500">AI 人相鑑定</h1>
            <p class="text-slate-400 text-sm">入るには ID とパスワードが必要です</p>
        </header>
        <form method="post" action="" class="glass-panel rounded-3xl p-6 space-y-5" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <p id="loginError" role="alert" class="rounded-xl border border-rose-400/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-200<?php echo $error === '' ? ' hidden' : ''; ?>"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="space-y-2">
                <label for="login_id" class="block text-sm text-slate-300">ID</label>
                <input id="login_id" name="login_id" type="text" inputmode="text" autocomplete="username" required maxlength="64" value="<?php echo htmlspecialchars($loginId, ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded-xl bg-slate-950/70 border border-white/10 px-4 py-3 text-white outline-none focus:border-emerald-400">
            </div>
            <div class="space-y-2">
                <label for="password" class="block text-sm text-slate-300">パスワード</label>
                <div class="flex gap-2">
                    <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="128" class="min-w-0 flex-1 rounded-xl bg-slate-950/70 border border-white/10 px-4 py-3 text-white outline-none focus:border-emerald-400">
                    <button type="button" id="togglePassword" aria-controls="password" aria-pressed="false" class="shrink-0 rounded-xl border border-white/10 bg-white/5 px-4 text-sm text-slate-200">表示</button>
                </div>
            </div>
            <button type="submit" class="w-full bg-gradient-to-r from-emerald-500 to-blue-600 text-white font-bold py-4 rounded-2xl">入る</button>
        </form>
    </main>
    <script>
        var idKey = 'ninsougaku_login_id';
        var passwordKey = 'ninsougaku_login_password';
        var form = document.querySelector('form');
        var idInput = document.getElementById('login_id');
        var passwordInput = document.getElementById('password');
        var errorBox = document.getElementById('loginError');
        var submitButton = form.querySelector('button[type="submit"]');

        document.getElementById('togglePassword').addEventListener('click', function () {
            var showing = passwordInput.type === 'text';
            passwordInput.type = showing ? 'password' : 'text';
            this.textContent = showing ? '表示' : '隠す';
            this.setAttribute('aria-pressed', showing ? 'false' : 'true');
        });

        form.addEventListener('submit', function () {
            try {
                sessionStorage.setItem('ninsougaku_pending_id', idInput.value.trim());
                sessionStorage.setItem('ninsougaku_pending_password', passwordInput.value);
            } catch (err) {}
        });

        try {
            var failed = errorBox && errorBox.textContent && !errorBox.classList.contains('hidden');
            if (failed) {
                localStorage.removeItem(passwordKey);
                sessionStorage.removeItem('ninsougaku_pending_id');
                sessionStorage.removeItem('ninsougaku_pending_password');
            }
            var savedId = localStorage.getItem(idKey) || '';
            var savedPassword = localStorage.getItem(passwordKey) || '';
            if (!failed && savedId && savedPassword) {
                idInput.value = savedId;
                passwordInput.value = savedPassword;
                form.submit();
            } else if (savedId && !idInput.value) {
                idInput.value = savedId;
            }
        } catch (err) {}
    </script>
</body>
</html>
