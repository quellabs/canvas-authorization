<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$page_title|default:"Finish signing in"}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans', Helvetica, Arial, sans-serif;
            background-color: #0d1117; color: #f0f6fc; line-height: 1.5;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .container {
            background-color: #161b22; border: 1px solid #30363d; border-radius: 6px;
            padding: 32px; width: 100%; max-width: 380px; box-shadow: 0 8px 24px rgba(140, 149, 159, 0.2);
        }
        .form-title { font-size: 22px; font-weight: 300; text-align: center; margin-bottom: 8px; }
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
        .divider { margin: 24px 0; text-align: center; position: relative; color: #7d8590; font-size: 12px; }
        .divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background-color: #30363d; }
        .divider span { background-color: #161b22; padding: 0 16px; position: relative; }
        .error-message, .errors-container .error-message {
            background-color: #490202; border: 1px solid #f85149; color: #ffa198;
            padding: 8px 12px; border-radius: 6px; font-size: 12px; margin-bottom: 8px;
        }
        .errors-container { margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="container">
    {if $oidcOnly}
        <h1 class="form-title">Create your account</h1>
        <p class="form-subtitle">You've signed in with your provider. Confirm to create your local profile.</p>

        {if $errors}
            <div class="errors-container">
                {foreach $errors as $field => $field_errors}
                    {foreach $field_errors as $error_message}
                        <div class="error-message">{$error_message}</div>
                    {/foreach}
                {/foreach}
            </div>
        {/if}

        <form action="/auth/oidc/complete/register" method="post">
            <input type="hidden" name="_csrf_token" value="{$csrfToken|default:''}">
            <button type="submit" class="btn">Confirm and create my account</button>
        </form>
    {else}
        <h1 class="form-title">Finish signing in</h1>
        <p class="form-subtitle">We don't recognize this provider identity yet. Sign in to an existing account to link it, or create a new one.</p>

        {if $errors}
            <div class="errors-container">
                {foreach $errors as $field => $field_errors}
                    {foreach $field_errors as $error_message}
                        <div class="error-message">
                            {if $field == 'general'}
                                {$error_message}
                            {else}
                                <strong>{$field|capitalize}:</strong> {$error_message}
                            {/if}
                        </div>
                    {/foreach}
                {/foreach}
            </div>
        {/if}

        <form action="/auth/oidc/complete/existing" method="post">
            <input type="hidden" name="_csrf_token" value="{$csrfToken|default:''}">
            <div class="form-group">
                <label for="existing-username" class="form-label">Username or email</label>
                <input type="text" id="existing-username" name="username" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="existing-password" class="form-label">Password</label>
                <input type="password" id="existing-password" name="password" class="form-input" required>
            </div>
            <button type="submit" class="btn">Sign in and link</button>
        </form>

        <div class="divider"><span>or create a new account</span></div>

        <form action="/auth/oidc/complete/register" method="post">
            <input type="hidden" name="_csrf_token" value="{$csrfToken|default:''}">
            <div class="form-group">
                <label for="register-name" class="form-label">Name</label>
                <input type="text" id="register-name" name="name" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="register-username" class="form-label">Username (email)</label>
                <input type="text" id="register-username" name="username" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="register-password" class="form-label">Password</label>
                <input type="password" id="register-password" name="password" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="register-confirm-password" class="form-label">Confirm password</label>
                <input type="password" id="register-confirm-password" name="confirm_password" class="form-input" required>
            </div>
            <button type="submit" class="btn">Create account and link</button>
        </form>
    {/if}
</div>
</body>
</html>
