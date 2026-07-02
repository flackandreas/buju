<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>BJS 2026 – Anmelden</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS Style -->
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-body">
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">🏆</div>
                <h2>Bundesjugendspiele 2026</h2>
                <p class="login-subtitle">Sportauswertung & Datenerfassung</p>
            </div>
            
            <?php if (!empty($login_error)): ?>
                <div class="login-error-banner">
                    ⚠️ <?php echo htmlspecialchars($login_error); ?>
                </div>
            <?php endif; ?>
            
            <form action="index.php" method="POST" autocomplete="off" class="login-form">
                <input type="hidden" name="login" value="1">
                
                <div class="login-input-group">
                    <label for="username">Benutzername</label>
                    <input type="text" id="username" name="username" required placeholder="z.B. buju_rstn" class="form-input" autofocus>
                </div>
                
                <div class="login-input-group">
                    <label for="password">Passwort</label>
                    <input type="password" id="password" name="password" required placeholder="••••••••" class="form-input">
                </div>
                
                <button type="submit" class="btn btn-primary login-btn">Anmelden</button>
            </form>
        </div>
        
        <!-- Theme Toggle on Login Screen -->
        <button id="theme-toggle-login" class="theme-toggle-btn login-theme-toggle">
            <span class="theme-toggle-icon">☀️</span>
            <span id="theme-toggle-text-login">Helles Design</span>
        </button>
    </div>

    <script>
        // Simple Theme Switcher for Login Page
        const themeToggle = document.getElementById('theme-toggle-login');
        const themeToggleText = document.getElementById('theme-toggle-text-login');
        const themeToggleIcon = themeToggle ? themeToggle.querySelector('.theme-toggle-icon') : null;
        
        const applyTheme = (theme) => {
            if (theme === 'light') {
                document.documentElement.classList.add('light-theme');
                if (themeToggleText) themeToggleText.textContent = '🌙 Dunkles Design';
            } else {
                document.documentElement.classList.remove('light-theme');
                if (themeToggleText) themeToggleText.textContent = '☀️ Helles Design';
            }
        };

        const savedTheme = localStorage.getItem('bjs_theme') || 'dark';
        applyTheme(savedTheme);

        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                const isLight = document.documentElement.classList.toggle('light-theme');
                const theme = isLight ? 'light' : 'dark';
                localStorage.setItem('bjs_theme', theme);
                applyTheme(theme);
            });
        }
    </script>
</body>
</html>
