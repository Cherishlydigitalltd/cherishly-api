<?php
// Basic protection — delete this file immediately after deploying
$secret = 'cherishly-deploy-2026';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

define('BASE', dirname(__DIR__));
chdir(BASE);

function run(string $cmd): string
{
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    return implode("\n", $output) . ($code !== 0 ? "\n[Exit code: $code]" : '');
}

$steps = [
    'composer install' => 'composer install --no-dev --optimize-autoloader --working-dir=' . BASE,
    'generate app key' => 'php artisan key:generate --force',
    'run migrations' => 'php artisan migrate --force',
    'storage link' => 'php artisan storage:link',
    'config cache' => 'php artisan config:cache',
    'route cache' => 'php artisan route:cache',
    'view cache' => 'php artisan view:cache',
    'fix storage permissions' => 'chmod -R 775 ' . BASE . '/storage ' . BASE . '/bootstrap/cache',
];

?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Cherishly Deploy</title>
    <style>
        body {
            font-family: monospace;
            background: #0f0f0f;
            color: #d4d4d4;
            padding: 30px;
        }

        h2 {
            color: #fff;
        }

        .step {
            margin-bottom: 20px;
        }

        .step h4 {
            margin: 0 0 6px;
            color: #60a5fa;
            text-transform: uppercase;
            font-size: 13px;
        }

        pre {
            background: #1a1a1a;
            padding: 12px 16px;
            border-radius: 6px;
            white-space: pre-wrap;
            word-break: break-all;
            font-size: 13px;
            margin: 0;
        }

        .ok {
            border-left: 3px solid #22c55e;
        }

        .fail {
            border-left: 3px solid #ef4444;
            color: #fca5a5;
        }

        .done {
            color: #22c55e;
            font-size: 16px;
            font-weight: bold;
            margin-top: 30px;
        }

        .warn {
            color: #facc15;
            margin-top: 20px;
            font-size: 13px;
        }
    </style>
</head>

<body>
    <h2>🚀 Cherishly Deploy</h2>

    <?php foreach ($steps as $label => $cmd): ?>
        <?php $output = run($cmd);
        $failed = str_contains($output, 'Exit code:'); ?>
        <div class="step">
            <h4>
                <?= htmlspecialchars($label) ?>
            </h4>
            <pre class="<?= $failed ? 'fail' : 'ok' ?>"><?= htmlspecialchars($output ?: '✓ done') ?></pre>
        </div>
    <?php endforeach; ?>

    <p class="done">✓ Deploy complete.</p>
    <p class="warn">⚠️ DELETE deploy.php from your public folder immediately after this.</p>
</body>

</html>