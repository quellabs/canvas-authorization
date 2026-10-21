<?php /** @var array<string, array<int, string>> $errors */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Sign in') ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans', Helvetica, Arial, sans-serif;
            background-color: #0d1117; color: #f0f6fc; line-height: 1.5;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .container {
            background-color: #161b22; border: 1px solid #30363d; border-radius: 6px;
            padding: 32px; width: 100%; max-width: 340px; box-shadow: 0 8px 24px rgba(140, 149, 159, 0.2);
        }
        .form-title { font-size: 24px; font-weight: 300; text-align: center; margin-bottom: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; }
        .form-input {
            width: 100%; padding: 8px 12px; font-size: 14px; line-height: 20px;
            background-color: #0d1117; border: 1px solid #30363d; border-radius: 6px; color: #f0f6fc;
        }
        .btn {
            width: 100%; padding: 8px 16px; font-size: 14px; font-weight: 500; line-height: 20px;
            border: 1px solid #238636; border-radius: 6px; cursor: pointer; background-color: #238636; color: #fff;
        }
        .forgot-password, .signup-link a { color: #58a6ff; text-decoration: none; }
        .forgot-password { display: block; margin-top: 16px; text-align: center; font-size: 12px; }
        .divider { margin: 24px 0; text-align: center; color: #7d8590; font-size: 12px; }
        .signup-link { text-align: center; margin-top: 16px; padding: 16px; border: 1px solid #30363d; border-radius: 6px; font-size: 14px; }
        .error-message { background-color: #490202; border: 1px solid #f85149; color: #ffa198; padding: 8px 12px; border-radius: 6px; font-size: 12px; margin-bottom: 8px; }
        .errors-container { margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="container">
    <h1 class="form-title">Sign in to your account</h1>

    <?php if (!empty($errors)): ?>
        <div class="errors-container">
            <?php foreach ($errors as $field => $fieldErrors): ?>
                <?php foreach ($fieldErrors as $errorMessage): ?>
                    <div class="error-message">
                        <?php if ($field === 'general'): ?>
                            <?= htmlspecialchars($errorMessage) ?>
                        <?php else: ?>
                            <strong><?= htmlspecialchars(ucfirst($field)) ?>:</strong> <?= htmlspecialchars($errorMessage) ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form action="/login" method="post">
        <div class="form-group">
            <label for="username" class="form-label">Username or email address</label>
            <input type="text" id="username" name="username" class="form-input" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="password" class="form-label">Password</label>
            <input type="password" id="password" name="password" class="form-input" required>
        </div>
        <button type="submit" class="btn">Sign in</button>
        <a href="/forgot-password" class="forgot-password">Forgot password?</a>
    </form>

    <?php if ($oidcEnabled ?? false): ?>
        <div class="divider">or</div>
        <a href="<?= htmlspecialchars($oidcStartUrl ?? '') ?>" class="btn" style="display:block;text-align:center;text-decoration:none;background-color:#21262d;border-color:#30363d;">
            Sign in with your organization
        </a>
    <?php endif; ?>

    <div class="divider">or</div>

    <div class="signup-link">New to our platform? <a href="/register">Create an account</a></div>
</div>
</body>
</html>
