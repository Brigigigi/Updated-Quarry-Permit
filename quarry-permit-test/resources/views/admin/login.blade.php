<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
    <div class="container">
        <div class="hero">
            <div class="hero__left">
                <h1 class="hero__title">Admin Portal</h1>
                <p class="hero__text">Manage submissions and configuration securely. Only authorized administrators can access this area. Please sign in with your admin credentials. If you reached this page by mistake, return to the main site.</p>
            </div>
            <div class="hero__right">
                <h3 class="hero__subtitle">Sign In</h3>

                @if($errors->any())
                    <div style="color:#b91c1c; background:#fee2e2; padding:8px 12px; border-radius:8px; margin-bottom:10px;">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.login.post') }}" style="display:flex; flex-direction:column; gap:10px;">
                    @csrf
                    <label for="username">Username</label>
                    <input id="username" type="text" name="username" value="{{ old('username') }}" required>
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required>
                    <button class="hero__btn" type="submit">Login</button>
                    <a class="hero__admin" href="/">Back to Site</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
