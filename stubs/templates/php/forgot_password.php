<?php /** @var array<string, array<int, string>> $errors */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Forgot password') ?></title>
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
        .form-subtitle { font-size: 13px; text-align: center; margin-bottom: 20px; color: #7d8590; }
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
        .back-link { display: block; margin-top: 16px; text-align: center; font-size: 12px; color: #58a6ff; text-decoration: none; }
        .error-message { background-color: #490202; border: 1px solid #f85149; color: #ffa198; padding: 8px 12px; border-radius: 6px; font-size: 12px; margin-bottom: 8px; }
        .errors-container { margin-bottom: 16px; }
        .info-message { background-color: #0d2818; border: 1px solid #2ea043; color: #7ee2a8; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="container">
    <h1 class="form-title">Forgot your password?</h1>

    <?php if ($submitted ?? false): ?>
        <div class="info-message">If an account exists for that address, we've sent a link to reset its password.</div>
        <a href="/login" class="back-link">Back to sign in</a>
    <?php else: ?>
        <p class="form-subtitle">Enter your email address and we'll send you a link to reset your password.</p>

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

        <form action="/forgot-password" method="post">
            <div class="form-group">
                <label for="username" class="form-label">Email address</label>
                <input type="text" id="username" name="username" class="form-input" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
            </div>
            <button type="submit" class="btn">Send reset link</button>
            <a href="/login" class="back-link">Back to sign in</a>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
