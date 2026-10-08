<?php /** @var string|null $error */ ?>
<form class="card login-card" method="post" action="<?= BASE_URL ?>/login">
    <h1>Iniciar sesion</h1>
    <?php if (!empty($error)): ?>
        <div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <label for="email">Correo</label>
    <input type="email" id="email" name="email" required autofocus value="operador@local">
    <label for="password">Clave</label>
    <input type="password" id="password" name="password" required>
    <div style="margin-top:1rem">
        <button class="btn" type="submit">Entrar</button>
    </div>
    <p class="muted" style="margin-top:1rem;font-size:.82rem">
        Operador por defecto: <span class="mono">operador@local</span> / <span class="mono">cambiar123</span>
        (cambialo en produccion).
    </p>
</form>
