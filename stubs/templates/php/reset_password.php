<?php /** @var array<string, array<int, string>> $errors */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Reset password') ?></title>
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
        .error-message { background-color: #490202; border: 1px solid #f85149; color: #ffa198; padding: 8px 12px; border-radius: 6px; font-size: 12px; margin-bottom: 8px; }
        .errors-container { margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="container">
    <h1 class="form-title">Reset your password</h1>

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

    <form action="/reset-password" method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '') ?>">
        <div class="form-group">
            <label for="password" class="form-label">New password</label>
            <input type="password" id="password" name="password" class="form-input" required>
        </div>
        <div class="form-group">
            <label for="confirm-password" class="form-label">Confirm new password</label>
            <input type="password" id="confirm-password" name="confirm_password" class="form-input" required>
        </div>
        <button type="submit" class="btn">Reset password</button>
    </form>
</div>
</body>
</html>
