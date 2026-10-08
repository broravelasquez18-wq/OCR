<?php
/** @var string $content */
/** @var string $titulo */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($titulo ?? 'Verificador OCR', ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head>
<body class="centered">
    <?= $content ?>
</body>
</html>
